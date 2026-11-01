<?php

declare(strict_types=1);

namespace Preflow\Folio\Geo;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Preflow\View\Geo\GeoConfig;
use Preflow\View\Geo\GeoEntry;

/**
 * Folio's published pages as GEO entries (llms.txt): the published records of
 * the types `config/geo.php` lists, at their public URL in a locale. Types
 * behind a frontend permission and records flagged by `noindex_field` are left
 * out; the home page comes first.
 */
final class FolioGeoEntries
{
    public function __construct(
        private readonly GeoConfig $config,
        private readonly TypeCatalog $catalog,
        private readonly Publisher $publisher,
        private readonly FrontendResolver $resolver,
    ) {}

    /** @return list<GeoEntry> */
    public function __invoke(string $locale): array
    {
        $entries = [];
        foreach ($this->config->types as $type) {
            $listing = $this->catalog->get($type);
            $route = $this->resolver->routeFor($type);
            if ($listing === null || $route === null || $route->permission !== null) {
                continue;
            }

            $query = $this->publisher->query($type);
            if (!$route->publishing) {
                $query = $query->where($route->publishedField, $route->publishedValue);
            }
            foreach ($query->get()->items() as $record) {
                $entry = $this->entry($record, $locale, self::titleField($record->getType()->fields, $listing->list));
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    private function entry(DynamicRecord $record, string $locale, ?string $titleField): ?GeoEntry
    {
        $values = $record->localized($locale);
        $noindex = $this->config->noindexField;
        if ($noindex !== null && isset($record->getType()->fields[$noindex]) && self::truthy($values->get($noindex))) {
            return null;
        }
        $url = $this->resolver->urlFor($record, $locale);
        if ($url === null) {
            return null;
        }

        $title = $titleField !== null ? self::text($values->get($titleField)) : '';
        $home = $this->resolver->pathFor($record, $locale) === '/';
        if ($home) {
            $url = rtrim($url, '/') . '/'; // "/de/", whose Markdown is "/de/index.md"
        }

        return new GeoEntry(
            title: $title !== '' ? $title : $url,
            url: $url,
            description: isset($record->getType()->fields['meta_description']) ? self::text($values->get('meta_description')) : '',
            section: 'Pages',
            order: $home ? 0 : 100,
        );
    }

    /**
     * The field a record's title comes from: `title`, else `name`, else the first list column.
     *
     * @param array<string, mixed> $fields the type's fields
     * @param array<string, mixed> $list   its admin list config
     */
    private static function titleField(array $fields, array $list): ?string
    {
        foreach (['title', 'name'] as $field) {
            if (isset($fields[$field])) {
                return $field;
            }
        }
        $first = ((array) ($list['columns'] ?? []))[0] ?? null;

        return is_string($first) ? $first : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value)
            ? trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            : '';
    }

    private static function truthy(mixed $value): bool
    {
        return is_string($value)
            ? !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true)
            : (bool) $value;
    }
}
