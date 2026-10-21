<?php

declare(strict_types=1);

namespace Preflow\Roles;

use Preflow\Auth\Access\Pattern;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Authenticatable;

/**
 * Simple roles: config/roles.php lists, per population, each role's permission
 * patterns. A user may do X when any of their roles (getRoles()) in their
 * population has a pattern matching X. Unknown roles grant nothing.
 */
final class ConfigRoleResolver implements PermissionResolver
{
    /** @var array<string, true> unknown roles already logged this request */
    private array $logged = [];

    /**
     * @param array<string, array<string, list<string>>> $roles population => role => patterns
     */
    public function __construct(
        private readonly array $roles,
        private readonly bool $debug = false,
    ) {}

    public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
    {
        $defined = is_array($this->roles[$population] ?? null) ? $this->roles[$population] : [];

        foreach ($user->getRoles() as $role) {
            if (!is_string($role) && !is_int($role)) {
                continue; // malformed role data grants nothing
            }
            $role = (string) $role;
            if (!isset($defined[$role])) {
                $this->logUnknown($population, (string) $role);
                continue;
            }
            if (!is_array($defined[$role])) {
                continue;
            }
            foreach ($defined[$role] as $pattern) {
                if (is_string($pattern) && Pattern::matches($pattern, $permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function logUnknown(string $population, string $role): void
    {
        $key = $population . '/' . $role;
        if (!$this->debug || isset($this->logged[$key])) {
            return;
        }
        $this->logged[$key] = true;
        error_log("preflow/roles: role \"{$role}\" is not defined for {$population} in config/roles.php; it grants nothing");
    }
}
