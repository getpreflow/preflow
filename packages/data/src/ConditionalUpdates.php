<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that writes some fields of one record only while one of its fields still holds an
 * expected version (compare-and-set): the other fields stay as stored, and of two writers holding
 * the same version only the first gets through. Every built-in driver implements it the same way
 * (SQL: one UPDATE of the named columns WHERE the version matches; JSON files: a read, compare and
 * write under the type's lock); DataManager::updateFieldsIfVersion() is the way to call it.
 *
 * @since 0.77
 */
interface ConditionalUpdates extends StorageDriver
{
    /**
     * Write $fields, and only those, to the record with $id if its $versionField holds $expected:
     * the whole number $expected (as a number or its canonical text, '5' not '05'), or, for null,
     * no whole number at all (NULL, '', no key). $fields may hold $versionField with its next value.
     *
     * @param array<string, mixed> $fields not empty; never the id field
     * @return bool whether it was written (false: no such record, or another version)
     */
    public function updateIfVersion(string $type, string|int $id, array $fields, string $versionField, ?int $expected, string $idField = 'uuid'): bool;
}
