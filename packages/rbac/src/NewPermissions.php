<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\Pattern;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Permissions\KnownPermissions;
use Preflow\View\TemplateEngineInterface;

/**
 * The permissions an update brought (Folio's KnownPermissions::fresh()) as whoever manages roles
 * sees them: a count for the Roles sidebar badge, the notice on the Roles list (one line per
 * permission group, with the roles that already cover it and how, an "Add to role…" picker and
 * Dismiss) and the dashboard line. Only for a user who may edit roles (rbac_role.edit); nothing
 * when Folio keeps no known list (no DataManager) or its table isn't there yet (a site not
 * migrated). Nothing here grants: NewPermissionsScreen does, on the user's choice.
 *
 * @since 0.53
 */
final class NewPermissions
{
    private const string AUDIENCE = 'rbac_role.edit';

    public function __construct(
        private readonly Container $container,
        private readonly DataManager $dm,
        private readonly Messages $messages,
    ) {}

    /**
     * The fresh permissions, sorted. Throws where Folio has no known list or its table is missing.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        if (!$this->container->bound(KnownPermissions::class)) {
            throw new \RuntimeException('Folio keeps no list of known permissions on this site (no DataManager).');
        }

        return $this->container->get(KnownPermissions::class)->fresh();
    }

    /** @param list<string> $names known for good */
    public function markKnown(array $names): void
    {
        $this->container->get(KnownPermissions::class)->markKnown($names);
    }

    /** The badge: how many permissions are new, for a user who may edit roles; else null. */
    public function count(): ?int
    {
        $fresh = $this->forManager();

        return $fresh === [] ? null : count($fresh);
    }

    /** The notice above the Roles list (HTML), or null. */
    public function notice(): ?string
    {
        $fresh = $this->forManager();
        if ($fresh === []) {
            return null;
        }
        $prefix = $this->prefix();

        return $this->engine()->render('@rbac/new-permissions.twig', [
            'groups' => $this->groups($fresh),
            'dismiss_url' => $prefix . '/' . NewPermissionsScreen::PATH . '/dismiss',
            'grant_url' => $prefix . '/' . NewPermissionsScreen::PATH . '/grant',
            'csrf' => $this->csrf(),
        ]);
    }

    /** The dashboard line (HTML), or null. */
    public function dashboard(): ?string
    {
        $fresh = $this->forManager();
        if ($fresh === []) {
            return null;
        }

        return $this->engine()->render('@rbac/new-permissions-dashboard.twig', [
            'count' => count($fresh),
            'url' => $this->prefix() . '/rbac_role',
        ]);
    }

    /**
     * The fresh permissions when the signed-in user may edit roles; [] for anyone else, and when
     * they can't be read (no known list, a site not migrated).
     *
     * @return list<string>
     */
    private function forManager(): array
    {
        try {
            return $this->container->get(Access::class)->can(self::AUDIENCE) ? $this->fresh() : [];
        } catch (\Throwable $e) {
            error_log('rbac: new permissions not shown: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The fresh names by the registry's group (a new content type's five permissions are one
     * line), in name order, each with the roles covering some of them: the role's patterns
     * that match (Pattern::matches), so "Administrator (*)", "Content (assets.*)". "roles" are
     * the staff roles that lack one of the names (a role covering them all, such as `*`, gains
     * nothing) and that the signed-in user may add all of them to (delegation, as in the role
     * editor: they cover the role as stored and every name). Member roles are never offered:
     * the permissions this notice announces are the admin's.
     *
     * @param list<string> $fresh
     * @return list<array{heading: string, names: list<string>, permissions: list<array{name: string, label: string}>, covered: list<array{label: string, patterns: list<string>}>, roles: list<array{id: string, label: string}>}>
     */
    private function groups(array $fresh): array
    {
        $declared = $this->container->get(Access::class)->registry()->all();
        $labels = $this->container->bound(DisplayLabels::class) ? $this->container->get(DisplayLabels::class) : null;
        $held = $this->container->get(PermissionCoverage::class)->held();
        $roles = $this->roles();

        $byGroup = [];
        foreach ($fresh as $name) {
            $byGroup[$declared[$name]['group'] ?? ''][] = $name;
        }
        $groups = [];
        foreach ($byGroup as $group => $names) {
            $group = (string) $group;
            $permissions = [];
            foreach ($names as $name) {
                $declaredLabel = $declared[$name]['label'] ?? '';
                $permissions[] = ['name' => $name, 'label' => $declaredLabel === '' ? $name : ($labels?->permission($name, $declaredLabel) ?? $declaredLabel)];
            }
            $covered = [];
            $pickable = [];
            foreach ($roles as $role) {
                $matching = array_values(array_filter($role['patterns'], static function (string $pattern) use ($names): bool {
                    foreach ($names as $name) {
                        if (Pattern::matches($pattern, $name)) {
                            return true;
                        }
                    }

                    return false;
                }));
                if ($matching !== []) {
                    $covered[] = ['label' => $role['label'], 'patterns' => $matching];
                }
                if ($role['population'] !== 'staff' || PermissionCoverage::coversAll($role['patterns'], $names)) {
                    continue;
                }
                if ($held === null || PermissionCoverage::coversAll($held, [...$role['patterns'], ...$names])) {
                    $pickable[] = ['id' => $role['id'], 'label' => $role['label']];
                }
            }
            $groups[] = [
                'heading' => $group === '' ? $this->messages->get('matrix.other_group') : ($labels?->permissionGroup($group, $names[0]) ?? $group),
                'names' => $names,
                'permissions' => $permissions,
                'covered' => $covered,
                'roles' => $pickable,
            ];
        }

        return $groups;
    }

    /** @return list<array{id: string, label: string, population: string, patterns: list<string>}> by population, then label */
    private function roles(): array
    {
        $roles = [];
        foreach ($this->dm->queryType('rbac_role')->get()->items() as $record) {
            $population = (string) $record->get('population');
            $name = trim((string) $record->get('name'));
            if ($name === '' || $population === '') {
                continue;
            }
            $roles[] = [
                'id' => (string) $record->getId(),
                'label' => $this->messages->roleLabel($population, $name, (string) ($record->get('label') ?: $name)),
                'population' => $population,
                'patterns' => RoleList::normalize($record->get('permissions')),
            ];
        }
        usort($roles, static fn (array $a, array $b): int => [$a['population'] === 'staff' ? 0 : 1, $a['population'], $a['label']] <=> [$b['population'] === 'staff' ? 0 : 1, $b['population'], $b['label']]);

        return $roles;
    }

    private function engine(): TemplateEngineInterface
    {
        return $this->container->get(TemplateEngineInterface::class);
    }

    /** The session's CSRF token for the notice's forms. */
    private function csrf(): string
    {
        $session = $this->container->bound(SessionInterface::class) ? $this->container->get(SessionInterface::class) : null;

        return $session instanceof SessionInterface ? (CsrfToken::fromSession($session)?->getValue() ?? '') : '';
    }

    /** Folio's prefix (config/folio.php "path", as Folio reads it). */
    private function prefix(): string
    {
        $file = $this->container->get(Application::class)->basePath('config/folio.php');
        $config = is_file($file) ? require $file : [];

        return rtrim((string) (is_array($config) ? ($config['path'] ?? '/folio') : '/folio'), '/');
    }
}
