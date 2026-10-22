<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Pattern;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Authenticatable;
use Preflow\Folio\Extension\ExtensionState;

/**
 * Roles managed in Folio (rbac_role records): a user may do X when any of their roles
 * (getRoles()) defined for their population has a pattern matching X. Unknown roles
 * grant nothing. Until folio:extensions:apply has installed the extension here (it creates
 * the staff role "admin"), staff may do everything, so turning RBAC on never locks out the
 * admin. Once installed, staff without a granting role get nothing.
 */
final class RbacResolver implements PermissionResolver
{
    /** @var array<string, true> messages already logged this request */
    private array $logged = [];

    private ?bool $installed = null;

    public function __construct(
        private readonly RoleStore $store,
        private readonly ExtensionState $state,
        private readonly bool $debug = false,
    ) {}

    public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
    {
        if ($population === 'staff' && !($this->installed ??= $this->state->installedVersion('rbac') !== null)) {
            $this->logOnce('bootstrap', 'preflow/rbac: the rbac extension is not installed here yet, so staff may do everything; run folio:extensions:apply');
            return true;
        }

        $defined = $this->store->rolesFor($population);
        if ($population === 'staff' && $defined === []) {
            $this->logOnce('no-staff-roles', 'preflow/rbac: no staff roles defined; run folio:extensions:apply or create one under Settings → Roles');
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

    private function logOnce(string $key, string $message): void
    {
        if (isset($this->logged[$key])) {
            return;
        }
        $this->logged[$key] = true;
        error_log($message);
    }
}
