<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\LiveContent;

/**
 * One-off content (site settings…): a type flagged "singleton" holds one
 * record whose id is the type key. Values come back localized in the
 * request's content locale and are memoized per request.
 */
final class Singletons
{
    /** @var array<string, DynamicRecord|null> */
    private array $records = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $values = [];

    /** @var array<string, list<array<int, mixed>>> the reads behind each memoised record (DataManager::captureReads()) */
    private array $reads = [];

    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly ContentLocale $contentLocale,
        private readonly ?LiveContent $live = null,
        private readonly ?SafeRichText $richText = null,
    ) {}

    /**
     * Empties the memo for a render the page cache may store (PageCache::isolate()): its key
     * doesn't hold the content mode, so a record read as a working copy is read anew as published.
     * Returns what puts the earlier memo back.
     *
     * @return \Closure(): void
     */
    public function isolate(): \Closure
    {
        $before = [$this->records, $this->values, $this->reads];
        $this->records = $this->values = $this->reads = [];

        return function () use ($before): void {
            [$this->records, $this->values, $this->reads] = $before;
        };
    }

    /** @return array<string, mixed>|null the localized values; null before the first save */
    public function get(string $type): ?array
    {
        $key = $this->memoKey($type) . '@' . $this->contentLocale->get();
        $record = $this->record($type); // reports its read on every call, memoised or not
        if (!array_key_exists($key, $this->values)) {
            $values = $record?->localized($this->contentLocale->get())->toArray();
            $this->values[$key] = $values !== null && $this->richText !== null
                ? $this->richText->fields($values, $record->getType()->fields)
                : $values;
        }

        return $this->values[$key];
    }

    public function record(string $type): ?DynamicRecord
    {
        $key = $this->memoKey($type);
        if (!array_key_exists($key, $this->records)) {
            [$this->records[$key], $this->reads[$key]] = $this->dm->captureReads(fn (): ?DynamicRecord => $this->catalog->get($type)?->singleton === true
                ? ($this->live !== null ? $this->live->find($type, $type) : $this->dm->findType($type, $type))
                : null);
        } else {
            // A memo hit is still a read: every page using the settings depends on them (page cache).
            $this->dm->replayReads($this->reads[$key]);
        }

        return $this->records[$key];
    }

    /** The memo holds working copies and published snapshots apart (the admin shows the former). */
    private function memoKey(string $type): string
    {
        return $this->live?->showsWorkingCopies() === true ? $type . '#working' : $type;
    }
}
