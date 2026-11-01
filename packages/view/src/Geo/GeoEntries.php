<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

/**
 * Where llms.txt gets its pages: sources add entries per locale, filters drop,
 * change or reorder them. Folio registers its published pages as a source;
 * sites add their own from a service provider:
 *
 *     $container->get(GeoEntries::class)->source(fn (string $locale) => [new GeoEntry(…)]);
 *     $container->get(GeoEntries::class)->filter(fn (array $entries, string $locale) => …);
 *
 * Also holds the site's own fields (name, summary…) per locale, which llms.txt
 * falls back to when `config/geo.php` names none.
 */
final class GeoEntries
{
    /** @var list<callable(string): iterable<GeoEntry>> */
    private array $sources = [];

    /** @var list<callable(list<GeoEntry>, string): array<GeoEntry>> */
    private array $filters = [];

    /** @var ?callable(string): array<string, mixed> */
    private $site = null;

    /** @param callable(string $locale): iterable<GeoEntry> $source */
    public function source(callable $source): void
    {
        $this->sources[] = $source;
    }

    /** @param callable(list<GeoEntry> $entries, string $locale): array<GeoEntry> $filter */
    public function filter(callable $filter): void
    {
        $this->filters[] = $filter;
    }

    /** @param callable(string $locale): array<string, mixed> $fields the site's fields in a locale */
    public function site(callable $fields): void
    {
        $this->site = $fields;
    }

    /** @return array<string, mixed> */
    public function siteFields(string $locale): array
    {
        return $this->site !== null ? ($this->site)($locale) : [];
    }

    /**
     * Every source's entries for a locale, filtered, then sorted: sections in
     * the order they first appear (the home page's — order 0 — first), within a
     * section by order, then title.
     *
     * @return list<GeoEntry>
     */
    public function for(string $locale): array
    {
        $entries = [];
        foreach ($this->sources as $source) {
            foreach ($source($locale) as $entry) {
                $entries[] = self::entry($entry);
            }
        }
        foreach ($this->filters as $filter) {
            $entries = array_map(self::entry(...), array_values($filter($entries, $locale)));
        }

        return self::sorted($entries);
    }

    /**
     * @param list<GeoEntry> $entries
     * @return list<GeoEntry>
     */
    private static function sorted(array $entries): array
    {
        $sections = [];
        foreach ($entries as $entry) {
            if ($entry->order === 0) {
                $sections[$entry->section] = 0;
                break;
            }
        }
        foreach ($entries as $entry) {
            $sections[$entry->section] ??= count($sections);
        }

        usort($entries, static fn (GeoEntry $a, GeoEntry $b): int =>
            [$sections[$a->section], $a->order] <=> [$sections[$b->section], $b->order]
            ?: strnatcasecmp($a->title, $b->title));

        return $entries;
    }

    private static function entry(mixed $entry): GeoEntry
    {
        if (!$entry instanceof GeoEntry) {
            throw new \UnexpectedValueException('GEO entry sources and filters must give GeoEntry objects, got ' . get_debug_type($entry) . '.');
        }

        return $entry;
    }
}
