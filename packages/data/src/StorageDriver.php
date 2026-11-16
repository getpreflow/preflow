<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * The storage a DataManager reads and writes types through.
 *
 * Text matching: `LIKE`, `NOT LIKE`, `CONTAINS`, search(), searchLoose() and so ⌘K's loose-search
 * set compare text character by character, as MySQL/MariaDB's LIKE does under an
 * accent-insensitive, case-insensitive collation: case and accents are ignored, and so are width
 * and compatibility forms ("ＡＢＣ", "①" are "abc", "1"). "über", "ÜBER" and "uber" find "Über",
 * "elise" finds "Élise", "istanbul" finds "İstanbul", "lodz" finds "Łódź"; the Kelvin sign is a
 * k and the long s ſ an s; but ß is one letter ("strasse" does not find "Straße", "STRAẞE" does),
 * and so are æ ("aegir" does not find "Ægir") and the dotless ı ("ilik" does not find "ılık").
 * `%` matches any text, `_` one character (an umlaut is one). Text is compared as stored (a
 * decomposed "Ü" is not promised to match). Exact comparisons (`=`, `IN`, unique checks, sorting)
 * keep respecting accents: "muller" finds "Muller" with `=`, and "Müller" too with `LIKE`.
 * Preflow\Data\Text\TextMatch is the rule in PHP; its matching table is derived from the server.
 *
 * @since 0.78 the text-matching rule above
 * @since 0.78.1 matching ignores accents (0.78 respected them)
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
