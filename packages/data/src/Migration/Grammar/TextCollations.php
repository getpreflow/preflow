<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

use Preflow\Data\Schema\ColumnKind;

/**
 * A grammar whose columns can carry the text rule (ignore case, respect accents; TextMatch): a
 * text field's column then compares, sorts, groups and keeps its unique index by the rule in the
 * database itself, so its indexes stay usable. MySQL/MariaDB name an accent-sensitive,
 * case-insensitive NO PAD collation the server has (MysqlDialect::MATCH_COLLATIONS), SQLite the
 * collation preflow_text that SqliteDialect::prepare() registers on a connection. Every other
 * column stays exact. A grammar without this interface keeps its own comparison for every column
 * (the data README, "Exact comparisons", says what that means for a site).
 *
 * Grammars::for() returns the grammar bound to the connection (on()).
 *
 * @since 0.78
 */
interface TextCollations
{
    /**
     * This grammar bound to the connection's text collation, which is found once per connection
     * (MySQL probes the server).
     *
     * @throws \LogicException when the connection cannot have it (SQLite: one not readied by SqliteDialect::prepare())
     */
    public function on(\PDO $pdo): static;

    /** The collation a text column names, or null (an unbound grammar, a server without one: text columns stay exact). */
    public function textCollation(): ?string;

    /** A text field's record column of this kind; recordColumnType() when textCollation() is null. */
    public function textColumnType(ColumnKind $kind, ?int $length = null): string;

    /** A migration's string() or text() column (not exact); columnType() for another kind or when textCollation() is null. */
    public function textType(string $kind, ?int $length = null): string;

    /**
     * A migration column of this kind that compares exactly: an exact() string() or text() (MySQL
     * names utf8mb4_bin, as a later ALTER TABLE would otherwise take the table default), any other
     * kind as columnType() has it.
     */
    public function exactType(string $kind, ?int $length = null): string;

    /**
     * Each column's collation, in table order: null for a column that holds no text (numbers),
     * the server's name otherwise ('BINARY' or 'utf8mb4_bin' for an exact one).
     *
     * @return array<string, ?string>
     */
    public function columnCollations(\PDO $pdo, string $table): array;

    /**
     * Gives the columns their new types (column => full type, a textColumnType() or
     * recordColumnType() result) in one statement, keeping their data, NULL / NOT NULL, defaults
     * and indexes and the other columns: MySQL one ALTER TABLE (a FULLTEXT index refuses columns
     * of mixed collations, so one column at a time can fail), SQLite one table rebuild. Nothing
     * for an empty map.
     *
     * @param array<string, string> $types
     */
    public function convertCollations(\PDO $pdo, string $table, array $types): void;
}
