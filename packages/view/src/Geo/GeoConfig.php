<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

/**
 * GEO settings from `config/geo.php`. Without the file, or with
 * `'enabled' => false`, GEO is off and nothing changes.
 */
final readonly class GeoConfig
{
    /**
     * @param array<string, string> $siteFields where name/summary come from in Folio's `site` singleton
     * @param list<string>          $types      Folio types listed in llms.txt
     * @param list<string>          $exclude    page paths without a Markdown view
     */
    public function __construct(
        public bool $enabled = false,
        public ?string $name = null,
        public ?string $summary = null,
        public array $siteFields = ['name' => 'name', 'summary' => 'meta_description'],
        public array $types = ['page'],
        public ?string $noindexField = null,
        public bool $full = true,
        public array $exclude = [],
    ) {}

    /** Read a config file; a missing file means GEO is off. */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        return self::fromArray((array) require $path);
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $string = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? $value : null;
        $strings = static fn (mixed $value): array => array_values(array_map('strval', (array) $value));

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            name: $string($config['name'] ?? null),
            summary: $string($config['summary'] ?? null),
            siteFields: array_map('strval', (array) ($config['site_fields'] ?? ['name' => 'name', 'summary' => 'meta_description'])),
            types: $strings($config['types'] ?? ['page']),
            noindexField: $string($config['noindex_field'] ?? null),
            full: (bool) ($config['full'] ?? true),
            exclude: $strings($config['exclude'] ?? []),
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** Whether a page path is listed in `exclude` (a trailing slash doesn't matter). */
    public function excludes(string $path): bool
    {
        $normalize = static fn (string $p): string => $p === '/' ? '/' : rtrim($p, '/');
        $path = $normalize($path);
        foreach ($this->exclude as $excluded) {
            if ($normalize($excluded) === $path) {
                return true;
            }
        }

        return false;
    }
}
