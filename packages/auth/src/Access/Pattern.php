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
}
