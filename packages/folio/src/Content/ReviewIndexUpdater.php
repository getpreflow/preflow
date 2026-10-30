<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\I18n\Machine\ReviewEntry;
use Preflow\I18n\Machine\ReviewIndex;

/**
 * Keeps the core review index (records holding machine translations nobody has checked) in step
 * with Folio's saves, publishes and deletes. An entry is keyed by the Folio type key and the
 * record id, and counts the unchecked texts (TranslationStatus::unchecked()) per language other
 * than the default.
 *
 * The index is a cache: writes that bypass Folio (imports, project code) leave it stale until the
 * record's next Folio save or a rebuild (folio:review-index). For the same reason refresh() and
 * forget() never fail the save they follow: an index that cannot be written is logged, not thrown.
 */
final class ReviewIndexUpdater
{
    /** @param \Closure(DynamicRecord): string $title the record's label, as Folio's lists show it */
    public function __construct(
        private readonly ReviewIndex $index,
        private readonly TranslationStatus $status,
        private readonly \Closure $title,
    ) {}

    /** Lists the record with its unchecked counts, or drops it when it has none. */
    public function refresh(DynamicRecord $record): void
    {
        // A single-language app has no translations: nothing to list, and no index file to touch on every save.
        if (!$record->getType()->locales->isMultilingual()) {
            return;
        }
        $type = $record->getType()->key;
        $id = (string) $record->getId();
        $this->safely($type, $id, function () use ($record, $type, $id): void {
            $counts = $this->counts($record);
            if ($counts === []) {
                $this->drop($type, $id);

                return;
            }
            $this->index->put($type, $id, ($this->title)($record), $counts);
        });
    }

    /** Drops a record (deleted, or left without unchecked texts). */
    public function forget(string $type, string $id): void
    {
        $this->safely($type, $id, fn () => $this->drop($type, $id));
    }

    private function drop(string $type, string $id): void
    {
        // Most saves concern records that are not listed: a read spares them the locked rewrite.
        foreach ($this->index->entries() as $entry) {
            if ($entry->collection === $type && $entry->id === $id) {
                $this->index->forget($type, $id);

                return;
            }
        }
    }

    /** Runs an index update; a failure is logged, never thrown into the save it follows. */
    private function safely(string $type, string $id, \Closure $update): void
    {
        try {
            $update();
        } catch (\Throwable $e) {
            error_log("Folio: could not update the review index for {$type}/{$id}: " . $e->getMessage());
        }
    }

    /** The record's entry for a rebuild (ReviewIndex::replaceAll()); null when it holds no unchecked texts. */
    public function entry(DynamicRecord $record, string $updatedAt): ?ReviewEntry
    {
        $counts = $this->counts($record);

        return $counts === [] ? null : new ReviewEntry($record->getType()->key, (string) $record->getId(), ($this->title)($record), $counts, $updatedAt);
    }

    /** @return array<string, int> unchecked texts per language other than the default; none left out */
    private function counts(DynamicRecord $record): array
    {
        $def = $record->getType();
        $counts = [];
        foreach ($def->locales->available as $locale) {
            if ($locale === $def->locales->default) {
                continue;
            }
            $count = count($this->status->unchecked($def, $record, $locale));
            if ($count > 0) {
                $counts[$locale] = $count;
            }
        }

        return $counts;
    }
}
