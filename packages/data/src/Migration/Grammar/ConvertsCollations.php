<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

/**
 * A grammar that gives the tables made before its columns had the text rule their new
 * collations (`preflow migrate`'s conversion, TextRuleConversion and CollationChange): it reads
 * each column's collation, changes a table's in one statement, reports what refuses a change, and
 * names rows for the duplicate lists. Also where the application supplies the collation (SQLite),
 * the version of the rule its indexes were built by and their rebuild. MySQL/MariaDB and SQLite
 * implement it. A grammar with TextCollations alone makes new columns right and leaves existing
 * ones as they are; `preflow migrate` says so once per storage.
 *
 * @since 0.78
 */
interface ConvertsCollations extends TextCollations
{
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
     * The version of the rule's implementation the indexes over text columns are ordered by, where
     * the application supplies the collation (SQLite: TextMatch::version(), with PHP's ICU). Null
     * only where the database keeps those indexes current itself (MySQL: the server supplies the
     * collation and rebuilds what it needs on an upgrade). When it changes, `preflow migrate`
     * rebuilds the indexes (reindexText()).
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
