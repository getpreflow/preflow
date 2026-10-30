<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * Where texts to translate live: Folio records, or a project's own data read from cron. The job
 * runner only sees items and units, so any data can be translated item by item.
 */
interface TextSource
{
    /** Stable key of the collection, e.g. a Folio type key. */
    public function collection(): string;

    /** Name shown to people, e.g. "Games". */
    public function label(): string;

    /** @return list<string> ids of every item */
    public function items(): array;

    public function title(string $id): string;

    /**
     * Units of one item for $to. Needed = missing + outdated (previousSource/current set), All =
     * every text that has a source. Opted-out fields never appear.
     *
     * @return list<TranslationUnit>
     */
    public function units(string $id, string $to, Scope $scope): array;

    /**
     * Stores texts (unit id => text) for $to as machine-made, marking them unchecked. Never publishes.
     *
     * @param array<string,string> $texts
     */
    public function write(string $id, string $to, array $texts): WriteOutcome;

    /** True when a write is public right away (the source has no publishing step). */
    public function writesLive(): bool;
}
