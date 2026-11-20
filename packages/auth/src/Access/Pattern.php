<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

/**
 * Permission patterns: "page.edit" matches itself; "page.*" matches every
 * permission below "page." (never "pages.edit" or "page" itself); "*" matches all.
 */
final class Pattern
{
    public static function matches(string $pattern, string $permission): bool
    {
        if ($pattern === '*' || $pattern === $permission) {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            return str_starts_with($permission, substr($pattern, 0, -1)) && strlen($permission) > strlen($pattern) - 1;
        }

        return false;
    }

    /**
     * Whether the patterns $held cover every pattern in $granted: each granted pattern is matched
     * by a held one as a name would be ("*" covers all, "page.*" covers "page.edit" and "page.*",
     * "page.edit" covers only itself). Resolvers whose grants are patterns compare roles with it
     * (ComparesRoles).
     *
     * @since 0.81.0
     *
     * @param list<string> $held
     * @param list<string> $granted
     */
    public static function covers(array $held, array $granted): bool
    {
        foreach ($granted as $pattern) {
            $covered = false;
            foreach ($held as $own) {
                if (self::matches($own, $pattern)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }

        return true;
    }
}
