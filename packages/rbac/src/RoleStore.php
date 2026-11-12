<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;

/**
 * The rbac_role records, loaded once per instance (one per request); whether rbac is installed
 * here (its table is there), installing it and the seed (the staff role "admin" and
 * config/roles.php) that `php preflow rbac:install` and Folio's rbac extension run.
 */
final class RoleStore
{
    /** The populations a role belongs to. */
    public const array POPULATIONS = ['staff', 'members'];

    /** The hidden rbac_role field holding what the seed has granted the role so far (since 0.53). */
    public const FIELD = 'seeded_permissions';

    /** @var array<string, array<string, list<string>>>|null population => role name => patterns */
    private ?array $roles = null;

    private ?bool $installed = null;

    public function __construct(private readonly DataManager $dm) {}

    /**
     * Whether rbac is installed here: the rbac_role table is there (`php preflow rbac:install`
     * or Folio's rbac extension made it; JSON storage: the type's directory, made by the seed).
     */
    public function installed(): bool
    {
        if ($this->installed === null) {
            $def = $this->definition();
            $this->installed = $def !== null && $this->dm->hasTable($def);
        }

        return $this->installed;
    }

    /** Creates the rbac_role table when it is missing (SQL storage; JSON storage makes it on the first write). */
    public function install(): void
    {
        $def = $this->definition() ?? throw new \LogicException('The rbac_role type is not defined; is RbacServiceProvider in config/providers.php?');
        (new RecordTableSync($this->dm))->sync($def);
        $this->forget();
    }

    /**
     * Seeds the roles (on every install or deploy): the staff role "admin" (everything) when
     * there is no staff role, then every role of $config (config/roles.php: preflow/roles'
     * format, population => name => patterns). ensure() creates a missing role and grants a
     * role only what its list newly names, once, so an editor's change stays.
     *
     * With $declared (the app's PermissionRegistry), a listed name that is not declared
     * (DeclaredPermissions) is still granted, so a deploy never depends on the order in which
     * packages declare, but reported: one line each, returned and logged.
     *
     * @param array<mixed> $config
     * @return list<string> the report: one line per undeclared name
     */
    public function seed(array $config, ?PermissionRegistry $declared = null): array
    {
        $this->forget(); // a table made earlier in this process (another instance, a migrate step)
        if ($this->rolesFor('staff') === []) {
            $this->ensure('staff', 'admin', 'Administrator', ['*']);
        }
        $report = [];
        foreach ($config as $population => $roles) {
            foreach (is_array($roles) ? $roles : [] as $name => $patterns) {
                $patterns = RoleList::normalize($patterns);
                foreach ($declared === null ? [] : DeclaredPermissions::undeclared($declared, $patterns) as $permission) {
                    $report[] = "config/roles.php: \"{$permission}\" ({$population} role \"{$name}\") is not a declared permission; granted anyway.";
                }
                $this->ensure((string) $population, (string) $name, self::label((string) $name), $patterns);
            }
        }
        foreach ($report as $line) {
            error_log('rbac: ' . $line);
        }

        return $report;
    }

    /** The roles a site seeds, from its config/roles.php ([] without one). @return array<mixed> */
    public static function config(string $file): array
    {
        $config = is_file($file) ? require $file : [];

        return is_array($config) ? $config : [];
    }

    /** A role's label from its name, as the seed makes it: "content_lead" → "Content lead". */
    public static function label(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }

    /** @return array<string, list<string>> role name => permission patterns */
    public function rolesFor(string $population): array
    {
        return $this->all()[$population] ?? [];
    }

    /**
     * Seeds a role and grants it what the seed adds later, once. A missing role is created with
     * the listed permissions, and the list is recorded in FIELD. An existing role gets only the
     * listed permissions never recorded before, appended to what it has; one an editor removed
     * stays removed (it is recorded) and one an editor added stays. A role without a record
     * (made before it was kept) is assumed to have had what it has now plus the list: the
     * record is made and nothing is added.
     *
     * @param list<string> $permissions
     */
    public function ensure(string $population, string $name, string $label, array $permissions): void
    {
        $listed = RoleList::normalize($permissions);
        $role = $this->dm->queryType(RoleType::KEY)->where('population', $population)->where('name', $name)->first();
        if (!$role instanceof DynamicRecord) {
            $type = $this->dm->getTypeRegistry()->get(RoleType::KEY);
            $this->dm->saveType(DynamicRecord::fromArray($type, [
                $type->idField => bin2hex(random_bytes(16)),
                'name' => $name,
                'label' => $label,
                'population' => $population,
                'permissions' => $listed,
                self::FIELD => self::encode($listed),
            ]), false);
            $this->forget();

            return;
        }
        $current = RoleList::normalize($role->get('permissions'));
        $raw = $role->get(self::FIELD);
        $hasRecord = $raw !== null && $raw !== '';
        $seeded = $hasRecord ? RoleList::normalize($raw) : RoleList::normalize([...$current, ...$listed]);
        $new = $hasRecord ? array_values(array_diff($listed, $seeded, $current)) : [];
        $record = RoleList::normalize([...$seeded, ...$listed]);
        if ($new === [] && $hasRecord && $record === $seeded) {
            return;
        }
        if ($new !== []) {
            $role->set('permissions', [...$current, ...$new]);
        }
        $role->set(self::FIELD, self::encode($record));
        $this->dm->saveType($role, false);
        $this->forget();
    }

    /** The population's role records, by name. @return array<string, DynamicRecord> */
    public function records(string $population): array
    {
        $records = [];
        foreach ($this->dm->queryType(RoleType::KEY)->where('population', $population)->get()->items() as $record) {
            $name = $record->get('name');
            if (is_string($name) && trim($name) !== '') {
                $records[trim($name)] = $record;
            }
        }
        ksort($records);

        return $records;
    }

    /**
     * Creates the role or changes it: $label (null: as it is; a new role's label is made from
     * its name), the patterns of $grant added, those of $revoke taken away. What the seed
     * recorded (FIELD) stays: a change here is an editor's change, as in an admin.
     *
     * @param list<string> $grant
     * @param list<string> $revoke
     * @return bool whether the role was created
     */
    public function put(string $population, string $name, ?string $label, array $grant, array $revoke): bool
    {
        $role = $this->records($population)[$name] ?? null;
        $created = $role === null;
        if ($role === null) {
            $type = $this->dm->getTypeRegistry()->get(RoleType::KEY);
            $role = DynamicRecord::fromArray($type, [
                $type->idField => bin2hex(random_bytes(16)),
                'name' => $name,
                'label' => self::label($name),
                'population' => $population,
                'permissions' => [],
            ]);
        }
        if ($label !== null) {
            $role->set('label', $label);
        }
        $current = RoleList::normalize($role->get('permissions'));
        $role->set('permissions', array_values(array_diff(RoleList::normalize([...$current, ...$grant]), $revoke)));
        $this->dm->saveType($role, false);
        $this->forget();

        return $created;
    }

    /** Deletes the role (nothing when there is none). */
    public function delete(string $population, string $name): void
    {
        $role = $this->records($population)[$name] ?? null;
        if ($role !== null) {
            $this->dm->deleteType(RoleType::KEY, (string) $role->getId());
            $this->forget();
        }
    }

    /** After a write: the roles are read again, and whether rbac is installed (a JSON write makes the type's directory). */
    private function forget(): void
    {
        $this->roles = null;
        $this->installed = null;
    }

    private function definition(): ?TypeDefinition
    {
        $registry = $this->dm->getTypeRegistry();

        return $registry !== null && $registry->has(RoleType::KEY) ? $registry->get(RoleType::KEY) : null;
    }

    /** @param list<string> $list */
    private static function encode(array $list): string
    {
        return (string) json_encode($list, JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, array<string, list<string>>> */
    private function all(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }
        if (!$this->installed()) {
            return $this->roles = []; // no table to read yet
        }
        $roles = [];
        foreach ($this->dm->queryType(RoleType::KEY)->get()->items() as $record) {
            $population = $record->get('population');
            $name = $record->get('name');
            if (!is_string($population) || !is_string($name) || trim($name) === '') {
                continue; // malformed role records grant nothing
            }
            $roles[$population][trim($name)] = RoleList::normalize($record->get('permissions'));
        }

        return $this->roles = $roles;
    }
}
