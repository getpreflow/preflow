<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** The one canonical form of a redirect path, used for storing and comparing alike. */
final class RedirectPath
{
    private const MAX_LENGTH = 300;

    /**
     * Collapses repeated slashes, drops a trailing slash and encodes each segment once, so
     * `/über-uns`, `/%C3%BCber-uns` and `/%c3%bcber-uns` give the same string. Null for
     * anything that isn't a plain site path.
     */
    public static function normalize(string $path): ?string
    {
        if (!str_starts_with($path, '/')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            $decoded = rawurldecode($segment);

            if ($decoded === '.' || $decoded === '..' || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1) {
                return null;
            }

            $segments[] = rawurlencode($decoded);
        }

        $normalized = '/' . implode('/', $segments);

        return strlen($normalized) > self::MAX_LENGTH ? null : $normalized;
    }

    /** What follows the area (`''` or `/…`) when $path is the area or below it, else null. */
    public static function under(string $path, string $area): ?string
    {
        if ($path === $area) {
            return '';
        }

        if (str_starts_with($path, $area . '/')) {
            return substr($path, strlen($area));
        }

        return null;
    }
}
