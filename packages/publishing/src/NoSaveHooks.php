<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/** The default SaveHooks: none; every type is saved by the storage. */
final class NoSaveHooks implements SaveHooks
{
    public function before(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void {}

    public function after(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void {}

    public function beforeDelete(DynamicRecord $record): void {}

    public function afterDelete(DynamicRecord $record): void {}

    public function replacesSave(string $type): bool
    {
        return false;
    }

    public function save(DynamicRecord $record, ?DynamicRecord $previous): void
    {
        throw new \LogicException("No SaveHooks save records of type \"{$record->getType()->key}\".");
    }
}
