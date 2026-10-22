<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

/**
 * The allowlist of files served under {prefix}/_assets: Folio's own, plus those packages
 * add for their field types (add() in a provider's boot(); the type's assets() names them).
 * One instance per application, read on every request, so files added at boot are served.
 */
final class AdminAssets
{
    /** @param array<string, string> $map URL filename => path relative to Folio's assets dir (or absolute) */
    public function __construct(
        private array $map = [],
    ) {}

    /** Serves the file at $path (absolute) as {prefix}/_assets/{file}; a .css, .js or .svg name not taken yet. */
    public function add(string $file, string $path): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*\.(css|js|svg)$/', $file) !== 1) {
            throw new \InvalidArgumentException("Admin asset \"{$file}\": the name must be a plain .css, .js or .svg file name.");
        }
        if (isset($this->map[$file])) {
            throw new \InvalidArgumentException("Admin asset \"{$file}\" is already taken.");
        }
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException("Admin asset \"{$file}\": the path must be absolute, got \"{$path}\".");
        }
        $this->map[$file] = $path;
    }

    public function get(string $file): ?string
    {
        return $this->map[$file] ?? null;
    }
}
