<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Pattern;
use Preflow\Auth\Access\PermissionRegistry;

/**
 * Which granted names are declared (auth's PermissionRegistry): "*", a declared permission, a
 * declared pattern or a pattern that covers a declared permission. `rbac:role` refuses the others
 * unless --allow-undeclared; the seed (config/roles.php) grants them and reports each.
 */
final class DeclaredPermissions
{
    /**
     * @param list<string> $patterns
     * @return list<string> those not declared, in order
     */
    public static function undeclared(PermissionRegistry $registry, array $patterns): array
    {
        return array_values(array_filter($patterns, static fn (string $p): bool => !self::declared($registry, $p)));
    }

    private static function declared(PermissionRegistry $registry, string $pattern): bool
    {
        if ($pattern === '*' || $registry->isDeclared($pattern) || in_array($pattern, $registry->patterns(), true)) {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            foreach (array_keys($registry->all()) as $permission) {
                if (Pattern::matches($pattern, (string) $permission)) {
                    return true;
                }
            }
        }

        return false;
    }
}
