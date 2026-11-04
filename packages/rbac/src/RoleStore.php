<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/** The rbac_role records, loaded once per instance (one per request). */
final class RoleStore
{
    /** The hidden rbac_role field holding what the seed has granted the role so far (since 0.53). */
    public const FIELD = 'seeded_permissions';

    /** @var array<string, array<string, list<string>>>|null population => role name => patterns */
    private ?array $roles = null;

    public function __construct(private readonly DataManager $dm) {}

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
        $role = $this->dm->queryType('rbac_role')->where('population', $population)->where('name', $name)->first();
        if (!$role instanceof DynamicRecord) {
            $type = $this->dm->getTypeRegistry()->get('rbac_role');
            $this->dm->saveType(DynamicRecord::fromArray($type, [
                $type->idField => bin2hex(random_bytes(16)),
                'name' => $name,
                'label' => $label,
                'population' => $population,
                'permissions' => $listed,
                self::FIELD => self::encode($listed),
            ]), false);
            $this->roles = null;

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
        $this->roles = null;
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
        $roles = [];
        foreach ($this->dm->queryType('rbac_role')->get()->items() as $record) {
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
