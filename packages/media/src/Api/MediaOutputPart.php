<?php

declare(strict_types=1);

namespace Preflow\Media\Api;

use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Data\Records\OutputPart;
use Preflow\Data\Records\ReadOptions;
use Preflow\Data\Records\RecordOutput;
use Preflow\Data\TypeDefinition;
use Preflow\Media\Asset;
use Preflow\Media\ImageTag;
use Preflow\Media\MediaLibrary;

/**
 * The media package's part of the read output (0.93): asked for with ReadOptions(with: ['media'])
 * (NAME), RecordOutput::toArray() gets a top-level "_media" map of every asset the record
 * references (RecordOutput::references(): its media fields, the blocks and columns of its blocks
 * fields, a page's areas) to what a client needs to show it:
 *
 *     "_media": {"i1": {"url": "/media/2026/11/i1.jpg", "kind": "image", "mime": "image/jpeg",
 *                       "width": 1200, "height": 800, "alt": {"en": "Sea", "de": "Meer"},
 *                       "sizes": {"480": "/media/2026/11/i1-480.webp", "768": "/media/2026/11/i1-768.webp"}}}
 *
 * - "url": the asset's URL (MediaLibrary::url(); a private asset's is gated).
 * - "kind": "image", "video" or "document"; "mime" its MIME type; "width" and "height" in pixels,
 *   or null (a document).
 * - "alt": the alt text, an object of each language => its text (null where none is written) on a
 *   site with more than one language, the text in ReadOptions::$locale (with the fallback chain)
 *   when one is asked for, and the text on a site with one language.
 * - "sizes": width => URL of the image files the image tag lists for its default widths
 *   (ImageTag::sizes()), narrow to wide; {} for a video, a document or a private asset.
 *
 * An id no asset has (deleted, or never one) is left out. Not asked for, the part adds nothing
 * and looks nothing up. Field values stay ids either way, so a field's shape never depends on
 * the query. The keys belong to the read output's version (RecordOutput::VERSION).
 *
 * @since 0.93.0
 */
final readonly class MediaOutputPart implements OutputPart
{
    /** The name a read asks for the part with (ReadOptions::$with). */
    public const string NAME = 'media';

    /** The key it adds. */
    public const string KEY = '_media';

    /**
     * @param \Closure(): RecordOutput $records the read class whose references() it covers
     * @param \Closure(): MediaLibrary $library
     * @param \Closure(): ImageTag     $images  the sizes' source
     * @param Locales $locales the site's languages, for the alt text
     *
     * The closures are asked only when a read asks for "media": other reads build nothing.
     */
    public function __construct(
        private \Closure $records,
        private \Closure $library,
        private \Closure $images,
        private Locales $locales,
    ) {}

    public function keys(TypeDefinition $def): array
    {
        return [self::KEY];
    }

    public function read(DynamicRecord $record, ReadOptions $options): array
    {
        if (!$options->wants(self::NAME)) {
            return [];
        }
        $ids = ($this->records)()->references($record)['asset'] ?? [];
        $library = ($this->library)();
        $library->prefetch($ids);
        $media = [];
        foreach ($ids as $id) {
            $asset = $library->find($id);
            if ($asset !== null) {
                $media[$id] = $this->entry($asset, $library, $options);
            }
        }

        return [self::KEY => $media === [] ? new \stdClass() : $media];
    }

    /** @return array{url: string, kind: string, mime: string, width: ?int, height: ?int, alt: mixed, sizes: array<int, string>|\stdClass} */
    private function entry(Asset $asset, MediaLibrary $library, ReadOptions $options): array
    {
        $sizes = ($this->images)()->sizes($asset);

        return [
            'url' => $library->url($asset),
            'kind' => $asset->kind(),
            'mime' => $asset->mime,
            'width' => $asset->width,
            'height' => $asset->height,
            'alt' => $this->alt($asset, $options),
            'sizes' => $sizes === [] ? new \stdClass() : $sizes,
        ];
    }

    private function alt(Asset $asset, ReadOptions $options): mixed
    {
        $text = static fn (mixed $one): ?string => is_string($one) && $one !== '' ? $one : null;
        if (!$this->locales->isMultilingual()) {
            return $text($asset->alt[$this->locales->default] ?? null);
        }
        if ($options->locale !== null) {
            return $text(LocaleValue::resolve($asset->alt, $this->locales, $options->locale)[0]);
        }
        $alt = [];
        foreach ($this->locales->available as $locale) {
            $alt[$locale] = $text($asset->alt[$locale] ?? null);
        }

        return $alt;
    }
}
