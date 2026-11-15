<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * The storage a DataManager reads and writes types through.
 *
 * Text matching: `LIKE`, `NOT LIKE`, `CONTAINS`, search(), searchLoose() and so ⌘K's loose-search
 * set compare text with Unicode simple case folding and nothing else: case is ignored in every
 * alphabet, accents and other marks count. "über" and "ÜBER" find "Über", "uber" does not; ß is one
 * letter ("strasse" does not find "Straße", "STRAẞE" does); the Kelvin sign is a k; "İSTANBUL" finds
 * "İstanbul", "istanbul" does not (İ has no simple fold); ı is a letter of its own ("ILIK" does not
 * find "ılık"). `%` matches any text, `_` one character (an umlaut is one). Text is compared as
 * stored (a decomposed "Ü" is not promised to match). Preflow\Data\Text\TextMatch is the rule in PHP.
 *
 * @since 0.78 the text-matching rule above
 */
interface StorageDriver
{
    /**
     * @return array<string, mixed>|null
     */
    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array;

    public function findMany(string $type, Query $query): ResultSet;

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void;

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void;

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool;

    public function lastInsertId(): string|int;
}
