<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\ListsRoles;
use Preflow\Auth\Access\Pattern;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Authenticatable;

/**
 * Stored roles (rbac_role records): a user may do X when any of their roles (getRoles())
 * defined for their population has a pattern matching X. Unknown roles grant nothing. Until
 * rbac is installed here (RoleStore::installed(): `php preflow rbac:install`, or Folio's rbac
 * extension, made the table and the staff role "admin"), staff may do everything, so turning
 * rbac on never locks out the admin. Once installed, staff without a granting role get nothing.
 */
final class RbacResolver implements PermissionResolver, ListsRoles
{
    /** @var array<string, true> messages already logged this request */
    private array $logged = [];

    public function __construct(
        private readonly RoleStore $store,
        private readonly bool $debug = false,
    ) {}

    /** The roles defined for the population (rbac_role records). */
    public function roles(string $population): array
    {
        return array_map('strval', array_keys($this->store->rolesFor($population)));
    }

    public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
    {
        if ($population === 'staff' && !$this->installed()) {
            return true;
        }

        $defined = $this->store->rolesFor($population);
        if ($population === 'staff' && $defined === []) {
            $this->logOnce('no-staff-roles', 'preflow/rbac: no staff roles defined; run php preflow rbac:install, or create one with php preflow rbac:role <name> --grant=<permissions>');
        }
        foreach (RoleList::normalize($user->getRoles()) as $role) {
            if (!isset($defined[$role])) {
                if ($this->debug) {
                    $this->logOnce($population . '/' . $role, "preflow/rbac: role \"{$role}\" is not defined for {$population}; it grants nothing");
                }
                continue;
            }
            foreach ($defined[$role] as $pattern) {
                if (Pattern::matches($pattern, $permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every pattern the user's roles in their population grant, in role order, each once;
     * ["*"] for staff until rbac is installed here (as allows() answers then).
     *
     * @return list<string>
     */
    public function patterns(Authenticatable $user, string $population): array
    {
        if ($population === 'staff' && !$this->installed()) {
            return ['*'];
        }
        $defined = $this->store->rolesFor($population);
        $patterns = [];
        foreach (RoleList::normalize($user->getRoles()) as $role) {
            array_push($patterns, ...($defined[$role] ?? []));
        }

        return array_values(array_unique($patterns));
    }

    /** Whether rbac is installed here (the store caches it and forgets it on install()); logs the bootstrap once while it is not. */
    private function installed(): bool
    {
        $installed = $this->store->installed();
        if (!$installed) {
            $this->logOnce('bootstrap', 'preflow/rbac: rbac is not installed here yet, so staff may do everything; run php preflow rbac:install');
        }

        return $installed;
    }

    private function logOnce(string $key, string $message): void
    {
        if (isset($this->logged[$key])) {
            return;
        }
        $this->logged[$key] = true;
        error_log($message);
    }
}
