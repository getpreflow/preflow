<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

/**
 * Versioned URLs for Folio's own admin assets: {prefix}/_assets/{file}?v={content hash},
 * so responses can be cached immutably and still bust on edit.
 */
final class AdminAssetUrls
{
    private readonly AdminAssets $map;

    /** @param array<string, string>|AdminAssets $map URL filename => path relative to $dir (or absolute) */
    public function __construct(
        private readonly string $prefix,
        private readonly string $dir,
        array|AdminAssets $map,
    ) {
        $this->map = $map instanceof AdminAssets ? $map : new AdminAssets($map);
    }

    public function __invoke(string $file): string
    {
        $rel = $this->map->get($file) ?? $file;
        $path = str_starts_with($rel, '/') ? $rel : $this->dir . '/' . $rel;
        $v = is_file($path) ? substr(hash_file('xxh3', $path), 0, 12) : 'dev';

        return $this->prefix . '/_assets/' . $file . '?v=' . $v;
    }
}
