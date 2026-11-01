<?php

declare(strict_types=1);

namespace Preflow\Folio\Seo;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\Singletons;
use Preflow\Media\MediaLibrary;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\SchemaRegistry;
use Preflow\View\Seo\SeoConfig;

/**
 * The share card and JSON-LD data of a Folio page, from its record. Each of
 * title, description and image is taken from the first of: the record's
 * "SEO & sharing" section, the fields `config/seo.php` maps for its type
 * (first non-empty; for an image field its first asset), the built-in fields
 * (`meta_title`, `title`, `name` / `meta_description`), and the `site`
 * singleton (its "SEO & sharing" section, then its `site_fields`). Share
 * texts count only in languages the record is visible in.
 */
final class FolioPageMeta
{
    private const array TITLE_FIELDS = ['meta_title', 'title', 'name'];
    private const array DESCRIPTION_FIELDS = ['meta_description'];

    public function __construct(
        private readonly SeoConfig $config,
        private readonly SchemaRegistry $schemas,
        private readonly MediaLibrary $media,
        private readonly Singletons $singletons,
    ) {}

    /**
     * @param string       $url              the page's absolute URL (its origin makes the image URL absolute)
     * @param list<string> $alternateLocales the other locales the page is visible in
     */
    public function for(DynamicRecord $record, string $locale, string $url, array $alternateLocales): PageMeta
    {
        $type = $record->getType()->key;
        $mapping = $this->config->types[$type] ?? null;
        $seo = SeoSection::fromRecord($record);
        $textLocale = self::textLocale($record, $locale);
        $site = $this->site();
        $siteSeo = $site !== null ? SeoSection::fromRecord($site) : null;
        $siteLocale = $site !== null ? self::textLocale($site, $locale) : null;

        $title = self::firstText([
            $textLocale !== null ? $seo->title($textLocale) : '',
            ...self::fieldValues($record, $locale, $mapping['title'] ?? []),
            ...self::fieldValues($record, $locale, self::TITLE_FIELDS),
            $siteLocale !== null ? (string) $siteSeo?->title($siteLocale) : '',
        ]);
        $description = self::firstText([
            $textLocale !== null ? $seo->description($textLocale) : '',
            ...self::fieldValues($record, $locale, $mapping['description'] ?? []),
            ...self::fieldValues($record, $locale, self::DESCRIPTION_FIELDS),
        ]);

        $image = $this->firstImage([$seo->image(), ...array_map(
            self::firstAssetId(...),
            self::fieldValues($record, $locale, $mapping['image'] ?? []),
        )], $locale);
        if ($description === '' || $image === null) {
            $defaults = $this->siteDefaults($locale);
            $description = $description !== '' ? $description : $defaults->description;
            $image ??= $defaults->image;
        }
        if ($image !== null) {
            $image['url'] = self::absolute($image['url'], $url);
        }

        $meta = new PageMeta(
            title: $title,
            description: $description,
            image: $image,
            url: $url,
            locale: $locale,
            alternateLocales: array_values(array_diff($alternateLocales, [$locale])),
            noindex: $seo->noindex(),
            ogType: $mapping['og_type'] ?? 'website',
        );

        return $meta->with(['schemas' => $this->schemas->nodesFor($type, $record->localized($locale)->toArray(), $locale, $meta)]);
    }

    /**
     * What the whole site says in a locale: its name (config, else the `site`
     * singleton's name field) as title, the site's share description (else its
     * description field) and its share image.
     */
    public function siteDefaults(string $locale): PageMeta
    {
        $site = $this->site();
        if ($site === null) {
            return new PageMeta(title: $this->config->name ?? '');
        }
        $seo = SeoSection::fromRecord($site);
        $textLocale = self::textLocale($site, $locale);
        $field = static fn (string $key): string => self::firstText(self::fieldValues($site, $locale, [$key]));

        return new PageMeta(
            title: $this->config->name ?? (isset($this->config->siteFields['name']) ? $field($this->config->siteFields['name']) : ''),
            description: self::firstText([
                $textLocale !== null ? $seo->description($textLocale) : '',
                isset($this->config->siteFields['description']) ? $field($this->config->siteFields['description']) : '',
            ]),
            image: $this->firstImage([$seo->image()], $locale),
        );
    }

    /**
     * A field value as one line of plain text: tags stripped, entities decoded, whitespace collapsed.
     *
     * @internal shared with FolioPublicPages
     */
    public static function text(mixed $value): string
    {
        return is_scalar($value)
            ? trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            : '';
    }

    private function site(): ?DynamicRecord
    {
        return $this->singletons->record('site');
    }

    /**
     * The locale the record's share texts are read in: the requested one when the record is
     * visible in it, the default for a type without translations (its texts are stored there),
     * else none.
     */
    private static function textLocale(DynamicRecord $record, string $locale): ?string
    {
        $type = $record->getType();
        if ($type->translatableFields() === []) {
            return $type->locales->default;
        }

        return in_array($locale, $record->availableLocales(), true) ? $locale : null;
    }

    /**
     * The values of those of $fields the record's type has, in that order.
     *
     * @param list<string> $fields
     * @return list<mixed>
     */
    private static function fieldValues(DynamicRecord $record, string $locale, array $fields): array
    {
        $values = $record->localized($locale);
        $known = $record->getType()->fields;

        return array_values(array_map(
            static fn (string $field): mixed => $values->get($field),
            array_filter($fields, static fn (string $field): bool => isset($known[$field])),
        ));
    }

    /** @param list<mixed> $candidates */
    private static function firstText(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $text = self::text($candidate);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * The share image of the first asset that can be one (a public image, not deleted).
     *
     * @param list<?string> $assetIds
     * @return ?array{url: string, width: int, height: int, alt: string}
     */
    private function firstImage(array $assetIds, string $locale): ?array
    {
        foreach ($assetIds as $id) {
            $image = $id !== null && $id !== '' ? $this->media->shareImage($id, $locale) : null;
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    /** The first asset id of an asset field's value: an id, a list of ids, or either as JSON text. */
    private static function firstAssetId(mixed $value): ?string
    {
        if (is_string($value) && (str_starts_with($value, '[') || str_starts_with($value, '{'))) {
            $value = json_decode($value, true);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $id = self::firstAssetId($item);
                if ($id !== null) {
                    return $id;
                }
            }

            return null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** $url made absolute with the origin of the page's URL; as it is when that has none. */
    private static function absolute(string $url, string $pageUrl): string
    {
        $page = parse_url($pageUrl);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1 || !isset($page['scheme'], $page['host'])) {
            return $url;
        }

        return $page['scheme'] . '://' . $page['host'] . (isset($page['port']) ? ':' . $page['port'] : '') . '/' . ltrim($url, '/');
    }
}
