<?php

declare(strict_types=1);

namespace Preflow\Folio\Seo;

use Preflow\Data\DynamicRecord;

/**
 * A record's "SEO & sharing" settings, stored as the reserved meta `_seo`:
 * share title and description per locale, a share image (asset id), and
 * whether the page is hidden from search engines, listed in the sitemap and
 * in the AI index (llms.txt). Nothing stored means the defaults: indexed and
 * listed everywhere, texts and image taken from the page itself.
 *
 * Stored shape (JSON text on SQL drivers):
 * ['title' => [locale => string], 'description' => [locale => string],
 *  'image' => ?string, 'noindex' => bool, 'sitemap' => bool, 'llms' => bool]
 */
final readonly class SeoSection
{
    /** The record key the settings are stored under. */
    public const string KEY = '_seo';

    /** The share image picker: one public image, like an asset field with this config. */
    public const array IMAGE_CONFIG = ['asset' => ['multiple' => false, 'accept' => 'image/*']];

    /**
     * @param array<string, string> $titles       by locale, no empty texts
     * @param array<string, string> $descriptions by locale, no empty texts
     */
    private function __construct(
        private array $titles = [],
        private array $descriptions = [],
        private ?string $image = null,
        private bool $noindex = false,
        private bool $sitemap = true,
        private bool $llms = true,
    ) {}

    public static function fromRecord(DynamicRecord $record): self
    {
        return self::fromValue($record->get(self::KEY));
    }

    /** The stored value: the array, its JSON text (SQL drivers), or nothing (the defaults). */
    public static function fromValue(mixed $stored): self
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (!is_array($stored)) {
            return new self();
        }

        return new self(
            self::texts($stored['title'] ?? []),
            self::texts($stored['description'] ?? []),
            self::text($stored['image'] ?? null),
            self::flag($stored['noindex'] ?? false),
            self::flag($stored['sitemap'] ?? true),
            self::flag($stored['llms'] ?? true),
        );
    }

    /**
     * The new `_seo` value from the editor's section (`_seo[...]`): the submitted title and
     * description replace those of $locale only (a type without translations keeps just that
     * one), image and flags replace the stored ones. The form's boxes say "leave out"
     * (exclude_sitemap, exclude_llms); the stored flags are positive.
     *
     * @param array<string, mixed> $submitted the posted `_seo` fields, image as the chosen asset id
     * @param array<string, mixed> $existing  the stored `_seo` value (decoded)
     * @return array{title: array<string, string>, description: array<string, string>, image: ?string, noindex: bool, sitemap: bool, llms: bool}
     */
    public static function fromSubmission(array $submitted, array $existing, string $locale, bool $translatable): array
    {
        $current = self::fromValue($existing);
        $merge = static function (array $stored, mixed $text) use ($locale, $translatable): array {
            $map = $translatable ? $stored : [];
            unset($map[$locale]);
            if (($text = self::text($text)) !== null) {
                $map[$locale] = $text;
            }

            return $map;
        };

        return (new self(
            $merge($current->titles, $submitted['title'] ?? null),
            $merge($current->descriptions, $submitted['description'] ?? null),
            self::text($submitted['image'] ?? null),
            self::checked($submitted['noindex'] ?? null),
            !self::checked($submitted['exclude_sitemap'] ?? null),
            !self::checked($submitted['exclude_llms'] ?? null),
        ))->toArray();
    }

    /** The share title in this locale; '' when none is set (no fallback: the caller decides). */
    public function title(string $locale): string
    {
        return $this->titles[$locale] ?? '';
    }

    /** The share description in this locale; '' when none is set. */
    public function description(string $locale): string
    {
        return $this->descriptions[$locale] ?? '';
    }

    /** The share image's asset id, if one is chosen. */
    public function image(): ?string
    {
        return $this->image;
    }

    /** Hidden from search engines. */
    public function noindex(): bool
    {
        return $this->noindex;
    }

    public function inSitemap(): bool
    {
        return $this->sitemap;
    }

    /** Listed in the AI index (llms.txt). */
    public function inLlms(): bool
    {
        return $this->llms;
    }

    /** Nothing differs from the defaults. */
    public function isEmpty(): bool
    {
        return $this->titles === [] && $this->descriptions === [] && $this->image === null
            && !$this->noindex && $this->sitemap && $this->llms;
    }

    /** @return array{title: array<string, string>, description: array<string, string>, image: ?string, noindex: bool, sitemap: bool, llms: bool} */
    public function toArray(): array
    {
        return [
            'title' => $this->titles,
            'description' => $this->descriptions,
            'image' => $this->image,
            'noindex' => $this->noindex,
            'sitemap' => $this->sitemap,
            'llms' => $this->llms,
        ];
    }

    /** @return array<string, string> */
    private static function texts(mixed $map): array
    {
        $texts = [];
        foreach (is_array($map) ? $map : [] as $locale => $text) {
            if (is_string($locale) && ($text = self::text($text)) !== null) {
                $texts[$locale] = $text;
            }
        }

        return $texts;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A stored flag: a boolean, or what SQL and JSON give back for one. */
    private static function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true) || $value === 'true';
    }

    /** A posted checkbox: present with any value but an empty one or '0'. */
    private static function checked(mixed $value): bool
    {
        return is_string($value) ? $value !== '' && $value !== '0' : $value === true || $value === 1;
    }
}
