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

    /**
     * Whether a column of this collation (a columnCollations() value) compares by the rule
     * already: textCollation(), or another name of the rule the database has (MySQL: any of
     * MysqlDialect::MATCH_COLLATIONS). `preflow migrate` leaves such a text column as it is.
     */
    public function isTextCollation(?string $collation): bool;

    /** The collation an exact column has, as columnCollations() reports it ('utf8mb4_bin', 'BINARY'). */
    public function exactCollation(): string;

    /** Whether a column of this collation (a columnCollations() value) compares exactly already. */
    public function isExactCollation(?string $collation): bool;

    /**
     * An existing column's type as columnTypes() reports it, given the rule's collation ($text) or
     * the exact one, for convertCollations().
     */
    public function withCollation(string $type, bool $text): string;

    /**
     * Why a column of this type and collation cannot be given another collation without changing
     * its values (MySQL: an ENUM or SET, a character set other than utf8mb4), or null when it can.
     * `preflow migrate` leaves such a column as it is and says why.
     */
    public function collationRefusal(string $type, ?string $collation): ?string;

    /** SQL for $expression compared in $collation (a name columnCollations() reports, textCollation(), exactCollation()). */
    public function collate(string $expression, string $collation): string;

    /**
     * Each unique index's columns that it holds by a prefix only (MySQL's index over the first
     * characters of a TEXT column), with the prefix's length in characters.
     *
     * @return array<string, array<string, int>> index name => column => length
     */
    public function uniquePrefixes(\PDO $pdo, string $table): array;

    /** SQL for the first $length characters of $expression (a prefix index's key). */
    public function prefix(string $expression, int $length): string;

    /**
     * The column that names a row of the table in a report: its primary key's (first) column, else
     * one the database keeps for every row (SQLite's rowid) or its first column.
     */
    public function rowKey(\PDO $pdo, string $table): string;

    /** @return list<string> the database's own tables (not views, not the database's internal ones), by name */
    public function tables(\PDO $pdo): array;

    /**
     * The version of the rule's implementation where the application supplies the collation and
     * the database keeps its indexes by it (SQLite: TextMatch::version()); null where the server
     * supplies it (MySQL). When it changes, `preflow migrate` rebuilds those indexes (reindexText()).
     */
    public function textCollationVersion(): ?string;

    /**
     * Rebuilds every index over a column of the text collation, each on its own, so they order and
     * compare by the rule as this application applies it now (textCollationVersion()). A unique
     * index that then holds two equal values cannot be rebuilt: it is left as it was (and still in
     * use) and returned. Nothing where the server supplies the collation (MySQL).
     *
     * @return list<array{table: string, index: string, columns: list<string>, error: string}> the indexes left as they were
     */
    public function reindexText(\PDO $pdo): array;
}
