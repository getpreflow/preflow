<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;
use Preflow\Validation\ValidationException;

/**
 * An app's own steps around each write of RecordWriter and RecordDeleter: before and after a save
 * or a delete, and a type's own save in place of the storage's. The default has none; Folio binds
 * the hooks of its type customizations (#[BeforeSave], #[AfterSave], #[BeforeDelete],
 * #[AfterDelete], #[SaveRecord]).
 */
interface SaveHooks
{
    /**
     * Before anything of the save is stored; may change the record. $kind is the kind of write
     * (SaveOptions::$kind; Reorder for RecordWriter::reorder(), Detach for a delete's detaches).
     *
     * @throws ValidationException refuses the save (nothing is stored)
     */
    public function before(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void;

    /** Once the record is stored (a working copy also when its publish failed); $kind as for before(). */
    public function after(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void;

    /** Before anything of the delete is written; any exception cancels the whole delete. */
    public function beforeDelete(DynamicRecord $record): void;

    /** Once the record is deleted. */
    public function afterDelete(DynamicRecord $record): void;

    /** Whether the type saves its records itself (save()) instead of the storage. Only for types that do not publish. */
    public function replacesSave(string $type): bool;

    /** Stores the record of a type that replacesSave(); $previous is the stored record (null: a new one). */
    public function save(DynamicRecord $record, ?DynamicRecord $previous): void;
}
