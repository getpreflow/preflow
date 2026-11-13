<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/**
 * The storage step RecordWriter and RecordDeleter share for a record of a type that does not
 * publish: write() stores it (the type's own save, SaveHooks::replacesSave(), else the storage's,
 * without rules), follow() then refreshes the index and syncs its addresses. No hooks, no checks:
 * the callers run those.
 *
 * @internal
 */
final class RecordStore
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly ?Publisher $publisher,
        private readonly SaveHooks $hooks,
        private readonly ?RecordIndex $index = null,
        private readonly ?RecordAddresses $addresses = null,
    ) {}

    /** The type's own save, else the storage's. A publishing type is always saved by the storage (#[SaveRecord]-style saves are for types without publishing). */
    public function write(DynamicRecord $record): void
    {
        $type = $record->getType()->key;
        if (!$this->publishes($type) && $this->hooks->replacesSave($type)) {
            $this->hooks->save($record, $this->dm->findType($type, (string) $record->getId()));
        } else {
            $this->dm->saveType($record, false);
        }
    }

    /**
     * After write(): the index and the record's addresses follow it.
     *
     * @return bool whether an address left the site
     */
    public function follow(DynamicRecord $record): bool
    {
        $this->index?->refresh($record);

        return $this->addresses?->sync($record->getType()->key, (string) $record->getId(), null) ?? false;
    }

    public function publishes(string $type): bool
    {
        return $this->publisher?->enabled($type) ?? false;
    }
}
