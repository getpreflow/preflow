<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

/**
 * Sign-in forms: a POST there whose session expired is let through when its origin passes
 * (the posted password is the proof). Packages add their login paths; sites add more
 * through `csrf.sign_in_paths`. Paths are matched exactly, without the locale prefix.
 */
final class CsrfSignInPaths
{
    /** @var array<string, true> */
    private array $paths = [];

    public function add(string $path): void
    {
        $this->paths[self::normalize($path)] = true;
    }

    public function matches(string $path): bool
    {
        return isset($this->paths[self::normalize($path)]);
    }

    private static function normalize(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
