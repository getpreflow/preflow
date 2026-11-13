<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\AfterDelete;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\BeforeDelete;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\Customization\Customizations;
use Preflow\Publishing\SaveHooks;
use Preflow\Publishing\WriteKind;

/**
 * The save and delete hooks of Folio's type customizations: #[BeforeSave], #[AfterSave] (the kind
 * of write to methods that take a second parameter typed WriteKind), #[BeforeDelete] (a Refused
 * cancels the delete), #[AfterDelete], and a type's #[SaveRecord] in place of the storage's save.
 */
final class FolioSaveHooks implements SaveHooks
{
    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    public function before(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void
    {
        $this->customizations->runHooks(BeforeSave::class, $record, $kind);
    }

    public function after(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void
    {
        $this->customizations->runHooks(AfterSave::class, $record, $kind);
    }

    public function beforeDelete(DynamicRecord $record): void
    {
        $this->customizations->runHooks(BeforeDelete::class, $record);
    }

    public function afterDelete(DynamicRecord $record): void
    {
        $this->customizations->runHooks(AfterDelete::class, $record);
    }

    public function replacesSave(string $type): bool
    {
        return $this->customizations->hasSaveRecord($type);
    }

    public function save(DynamicRecord $record, ?DynamicRecord $previous): void
    {
        if (!$this->customizations->saveRecord($record, $previous)) {
            throw new \LogicException("Type \"{$record->getType()->key}\" has no #[SaveRecord].");
        }
    }
}
