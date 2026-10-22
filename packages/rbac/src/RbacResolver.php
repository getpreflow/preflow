<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Pattern;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Authenticatable;

/**
 * Roles managed in Folio (rbac_role records): a user may do X when any of their roles
 * (getRoles()) defined for their population has a pattern matching X. Unknown roles
 * grant nothing. Until a staff role exists (folio:extensions:apply creates one), staff
 * may do everything, so turning RBAC on never locks out the admin.
 */
final class RbacResolver implements PermissionResolver
{
    /** @var array<string, true> messages already logged this request */
    private array $logged = [];

    public function __construct(
        private readonly RoleStore $store,
        private readonly bool $debug = false,
    ) {}

    public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
    {
        if ($population === 'staff' && $this->store->rolesFor('staff') === []) {
            $this->logOnce('bootstrap', 'preflow/rbac: no staff roles exist yet, so staff may do everything; run folio:extensions:apply');
            return true;
        }

        $defined = $this->store->rolesFor($population);
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

    private function logOnce(string $key, string $message): void
    {
        if (isset($this->logged[$key])) {
            return;
        }
        $this->logged[$key] = true;
        error_log($message);
    }
}
