<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * The records that hold unchecked machine translations, kept apart from the records themselves so a
 * dashboard can list them without loading every record.
 */
interface ReviewIndex
{
    /** @param array<string, int> $counts unchecked translations per locale; all zero (or empty) forgets the record */
    public function put(string $collection, string $id, string $title, array $counts): void;

    public function forget(string $collection, string $id): void;

    /** @return list<ReviewEntry> newest first */
    public function entries(): array;

    /** @param list<ReviewEntry> $entries */
    public function replaceAll(array $entries): void;
}
