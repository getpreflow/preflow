<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * The storage a DataManager reads and writes types through.
 *
 * Text matching: `LIKE`, `NOT LIKE`, `CONTAINS`, search(), searchLoose() and so ⌘K's loose-search
 * set compare text character by character, as MySQL/MariaDB's LIKE does under an accent-sensitive,
 * case-insensitive collation: case is ignored, and so are width and compatibility forms ("ＡＢＣ",
 * "①" are "abc", "1"); accents and other marks count. "über" and "ÜBER" find "Über", "uber" does
 * not; ß is one letter ("strasse" does not find "Straße", "STRAẞE" does); the Kelvin sign is a k;
 * the long s ſ is not an s; "İSTANBUL" finds "İstanbul", "istanbul" does not; ı is a letter of its
 * own ("ILIK" does not find "ılık"). `%` matches any text, `_` one character (an umlaut is one).
 * Text is compared as stored (a decomposed "Ü" is not promised to match).
 * Preflow\Data\Text\TextMatch is the rule in PHP; its fold table is derived from the server.
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
     * Writes the given fields of the record with that id and leaves every other stored field as it
     * is; a field given as null is stored as null; a missing record is inserted with the given
     * fields.
     *
     * @param array<string, mixed> $data
     * @since 0.78 the rule above holds on every driver (the JSON driver replaced the file before)
     */
    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void;

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void;

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool;

    public function lastInsertId(): string|int;
}
