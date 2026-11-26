<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Core\Context\Contexts;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldVisibility;
use Preflow\Publishing\LiveContent;

/**
 * One-off content (site settings…): a type flagged "singleton" holds one
 * record whose id is the type key. Values come back localized in the
 * render's content locale and are memoized per render frame and context (ContentMemo: by type
 * and RenderContext::memoKey(), which holds the read mode and the locale); not memoized
 * outside every render. A field its show_when hides reads as null (FieldVisibility::visibleValues();
 * since 0.91.0), also inside its blocks fields (BlockRegistry::visibleBlocksIn()); record() gives the
 * stored record.
 */
final class Singletons
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly ContentLocale $contentLocale,
        private readonly ?LiveContent $live = null,
        private readonly ?SafeRichText $richText = null,
        private readonly ?\Preflow\Blocks\BlockRegistry $blocks = null,
    ) {}

    /** @return array<string, mixed>|null the localized values; null before the first save */
    public function get(string $type): ?array
    {
        $record = $this->record($type); // reports its read on every call, memoised or not
        $values = function () use ($record): ?array {
            $values = $record === null ? null : FieldVisibility::visibleValues($record->getType(), $record->localized($this->contentLocale->get())->toArray());
            if ($values !== null && $this->blocks !== null) {
                $values = $this->blocks->visibleBlocksIn($values, $record->getType()->fields);
            }

            return $values !== null && $this->richText !== null
                ? $this->richText->fields($values, $record->getType()->fields)
                : $values;
        };
        $memo = self::memo();
        if ($memo === null) {
            return $values();
        }
        $key = 'singleton-values:' . $type . '|' . Contexts::current()->memoKey();
        if (!isset($memo->entries[$key])) {
            $memo->entries[$key] = [$values(), []];
        }

        return $memo->entries[$key][0];
    }

    public function record(string $type): ?DynamicRecord
    {
        $read = fn (): ?DynamicRecord => $this->catalog->get($type)?->singleton === true
            ? ($this->live !== null ? $this->live->find($type, $type) : $this->dm->findType($type, $type))
            : null;
        $memo = self::memo();
        if ($memo === null) {
            return $read();
        }
        $key = 'singletons:' . $type . '|' . Contexts::current()->memoKey();
        if (!isset($memo->entries[$key])) {
            $memo->entries[$key] = $this->dm->captureReads($read);
        } else {
            // A memo hit is still a read: every page using the settings depends on them (page cache).
            $this->dm->replayReads($memo->entries[$key][1]);
        }

        return $memo->entries[$key][0];
    }

    /** The current frame's memo; null outside every render (not memoized there). */
    private static function memo(): ?ContentMemo
    {
        return Contexts::slot(ContentMemo::class, static fn (): ContentMemo => new ContentMemo());
    }
}
