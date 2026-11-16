<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that guards unique fields itself: save() refuses a duplicate in a unique index the
 * schema made (RecordTableSync) by throwing UniqueViolation, and writes nothing. DataManager
 * then checks before the write only the unique fields no index can hold (translatable ones,
 * whose values sit in one locale map); on a storage without this marker it checks every unique
 * field, under the type's lock where the storage has one (ExclusiveWrites).
 *
 * A refused save leaves the caller's open transaction usable: the caller catches the
 * UniqueViolation (a form shows it) and goes on writing in the same transaction. SQLite and
 * MySQL undo only the failed statement; a database that aborts the whole transaction on a failed
 * statement (PostgreSQL) needs a savepoint around the write inside an open transaction.
 *
 * A marker: it adds no method. Claim it only when every unique index RecordTableSync asks for
 * exists and is enforced on save.
 *
 * @since 0.78
 */
interface UniqueIndexes extends StorageDriver
{
}
