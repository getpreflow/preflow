<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\UniqueViolation;

final class MysqlDialect implements Dialect, PatternMatching
{
    /**
     * The accent-sensitive, case-insensitive collations LIKE compares with, in order of
     * preference: MariaDB 10.10+'s UCA 14 one, MySQL 8's UCA 9 one (MariaDB 11.4.5+ knows it too,
     * as an alias). Both fold case as the rule does and keep accents.
     *
     * @since 0.78
     */
    public const array MATCH_COLLATIONS = ['utf8mb4_uca1400_as_ci', 'utf8mb4_0900_as_ci'];

    /** @var \Closure(): ?string|null resolves the collation on the first LIKE */
    private ?\Closure $resolveCollation = null;

    private ?string $matchCollation = null;

    /**
     * @param \Closure(): ?string|string|null $matchCollation The collation LIKE compares with (one
     *     of MATCH_COLLATIONS the server has); null for none (LIKE lowers both sides instead, see
     *     likeSql()); a closure naming it (or null), called once, on the first LIKE.
     */
    public function __construct(\Closure|string|null $matchCollation = null)
    {
        if ($matchCollation instanceof \Closure) {
            $this->resolveCollation = $matchCollation;
        } else {
            $this->matchCollation = self::collationName($matchCollation);
        }
    }

    /**
     * The first of MATCH_COLLATIONS the server has for utf8mb4, or null when it has none
     * (MariaDB 10.6 to 10.9). Read from the server's collation list, not inferred from
     * its version: MariaDB 10.10+ lists its UCA 14 collations without a character set
     * ("uca1400_as_ci", applicable to utf8mb4, used as utf8mb4_uca1400_as_ci), which a lookup by
     * the full name misses; MySQL 8 and MariaDB 11.4.5+ list utf8mb4_0900_as_ci itself.
     * information_schema.COLLATION_CHARACTER_SET_APPLICABILITY has both forms on every version.
     *
     * @since 0.78
     */
    public static function serverMatchCollation(\PDO $pdo): ?string
    {
        $names = [];
        foreach (self::MATCH_COLLATIONS as $collation) {
            $names[$collation] = $collation;
            $names[substr($collation, strlen('utf8mb4_'))] = $collation; // MariaDB's name without the character set
        }
        $statement = $pdo->prepare(
            "SELECT COLLATION_NAME FROM information_schema.COLLATION_CHARACTER_SET_APPLICABILITY WHERE CHARACTER_SET_NAME = 'utf8mb4' AND COLLATION_NAME IN ("
            . implode(', ', array_fill(0, count($names), '?')) . ')',
        );
        $statement->execute(array_keys($names));
        $listed = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $listed[$names[(string) $name] ?? ''] = true;
        }
        $statement->closeCursor();
        foreach (self::MATCH_COLLATIONS as $collation) {
            if (isset($listed[$collation])) {
                return $collation;
            }
        }

        return null;
    }

    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function updateSql(string $table, array $columns, string $idField): string
    {
        $sets = array_map(fn (string $c) => $this->quoteIdentifier($c) . ' = ?', $columns);

        return sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->quoteIdentifier($table),
            implode(', ', $sets),
            $this->quoteIdentifier($idField),
        );
    }

    public function insertSql(string $table, array $columns): string
    {
        $quoted = array_map(fn (string $c) => $this->quoteIdentifier($c), $columns);
        $placeholders = array_fill(0, count($columns), '?');

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', $quoted),
            implode(', ', $placeholders),
        );
    }

    /** No single statement: ON DUPLICATE KEY UPDATE would update whichever row a unique index matches. */
    public function upsertSql(string $table, array $columns, string $idField): ?string
    {
        return null;
    }

    /** ON DUPLICATE KEY UPDATE, which also fires on another unique index (see Dialect). */
    public function insertOrUpdateSql(string $table, array $columns, string $idField, array $set): ?string
    {
        return $this->insertSql($table, $columns) . ' ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(fn (string $column, string $expression): string => $this->quoteIdentifier($column) . ' = ' . $expression, array_keys($set), $set));
    }

    /**
     * Error 1062 on the key named PRIMARY; any other unique index is a different failure. The key
     * ends the message ("for key 'PRIMARY'", MySQL 8: "for key 'table.PRIMARY'"); the duplicate
     * value before it may hold any text.
     */
    public function isPrimaryKeyViolation(\PDOException $e, string $table, string $idField): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 && self::duplicateKey($e, $table) === 'PRIMARY';
    }

    /** Error 1062 on a key other than PRIMARY, with the key's name. */
    public function uniqueViolation(\PDOException $e, string $table, string $idField): ?UniqueViolation
    {
        if (($e->errorInfo[1] ?? null) !== 1062) {
            return null;
        }
        $key = self::duplicateKey($e, $table);

        return $key === null || $key === 'PRIMARY' ? null : new UniqueViolation($e, $key);
    }

    /** The key a 1062 names, without MySQL 8's table prefix. */
    private static function duplicateKey(\PDOException $e, string $table): ?string
    {
        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());
        if (preg_match("/for key '([^']+)'\\s*\$/", $message, $m) !== 1) {
            return null;
        }

        return str_starts_with($m[1], $table . '.') ? substr($m[1], strlen($table) + 1) : $m[1];
    }

    public function lockingRead(): string
    {
        return ' FOR UPDATE';
    }

    public function localizedColumn(string $quotedColumn, string $locale, bool $isDefault): string
    {
        if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid locale "%s".', $locale));
        }
        $plain = $isDefault ? $quotedColumn : 'NULL';

        return "(CASE WHEN JSON_VALID({$quotedColumn}) AND JSON_TYPE({$quotedColumn}) = 'OBJECT'"
            . " THEN JSON_UNQUOTE(JSON_EXTRACT({$quotedColumn}, '$.\"{$locale}\"')) ELSE {$plain} END)";
    }

    /**
     * Compared as text: MySQL takes '' for 0 when comparing a number, so NULLIF on the number
     * column itself would turn 0 into NULL. Adding the double 0e0 also works where CAST(… AS
     * DOUBLE) does not (MariaDB before 10.4.5); a plain "+ 0" gives MAX() a type without
     * decimals on MariaDB (7.5 came back as 8).
     */
    public function numericValue(string $expression): string
    {
        return "(NULLIF(CAST({$expression} AS CHAR), '') + 0e0)";
    }

    /**
     * Strict mode makes CAST('' AS SIGNED) and CAST('3.0' AS SIGNED) errors inside an UPDATE, and
     * a number column compared with '' too: only text that is a number is cast (through DECIMAL),
     * and '' is checked on the text form.
     */
    public function wholeNumber(string $expression): string
    {
        $text = "CAST({$expression} AS CHAR)";

        return "(CASE WHEN {$expression} IS NULL OR {$text} = '' THEN NULL WHEN {$text} REGEXP '^-?[0-9]+([.][0-9]*)?$' "
            . "THEN CAST(TRUNCATE(CAST({$text} AS DECIMAL(65,10)), 0) AS SIGNED) ELSE 0 END)";
    }

    /**
     * The plain operator, used only by a compiler that does not ask likeSql() (PatternMatching):
     * under the tables' collation it ignores case, but also accents.
     */
    public function likeOperator(): string
    {
        return 'LIKE';
    }

    /**
     * LIKE under the text-matching rule (case ignored in every alphabet, accents count). With a
     * collation (MATCH_COLLATIONS) the column is compared in it; the CAST lets a number column or
     * one of another character set take the COLLATE (as in exactText()), which also means a
     * prefix pattern does not use an index. Without one both sides are lowered and compared by
     * code point (utf8mb4_bin), which the data README's "Text matching" says where it differs.
     */
    public function likeSql(string $column, string $pattern, bool $not = false, ?string $escape = null): string
    {
        $like = ($not ? ' NOT' : '') . ' LIKE ';
        $escapeSql = $escape === null ? '' : " ESCAPE '" . str_replace("'", "''", $escape) . "'";
        $collation = $this->matchCollation();
        if ($collation !== null) {
            return "(CAST({$column} AS CHAR CHARACTER SET utf8mb4) COLLATE {$collation}){$like}?{$escapeSql}";
        }

        return "LOWER(CAST({$column} AS CHAR CHARACTER SET utf8mb4)) COLLATE utf8mb4_bin{$like}LOWER(CONVERT(? USING utf8mb4)) COLLATE utf8mb4_bin{$escapeSql}";
    }

    /** The collation LIKE compares with, or null for the LOWER() fallback (a closure resolved once). */
    private function matchCollation(): ?string
    {
        if ($this->resolveCollation !== null) {
            $this->matchCollation = self::collationName(($this->resolveCollation)()); // a failure is asked again on the next LIKE
            $this->resolveCollation = null;
        }

        return $this->matchCollation;
    }

    /** A collation name goes into the SQL as it is: letters, digits and underscores only. */
    private static function collationName(?string $name): ?string
    {
        if ($name !== null && preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid collation name "%s".', $name));
        }

        return $name;
    }

    /** "Unknown column 'x' in 'field list'" (error 1054). */
    public function missingColumn(\PDOException $e): ?string
    {
        return preg_match('/Unknown column\s*[\'"`]?([A-Za-z0-9_]+)/', $e->getMessage(), $m) === 1 ? $m[1] : null;
    }

    /** "+ 0" makes stored text numeric. */
    public function numericOrder(string $expression): string
    {
        return "({$expression} + 0)";
    }

    /**
     * The tables' collations ignore case; utf8mb4_bin compares code points. The cast first
     * gives any expression (a JSON value, a column of another charset) the charset utf8mb4_bin
     * belongs to.
     */
    public function exactText(string $expression): string
    {
        return "(CAST({$expression} AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin)";
    }

    public function inList(string $quotedColumn, array $ids, array &$bindings): string
    {
        array_push($bindings, ...array_values($ids));

        return "{$quotedColumn} IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
    }

    /**
     * One IN list for the plain id and one for the array's members, read with JSON_TABLE
     * (MariaDB 10.6+, MySQL 8): 2 bindings per id.
     */
    public function jsonArrayHasAny(string $quotedColumn, array $ids, array &$bindings): string
    {
        $ids = array_values($ids);
        $in = '(' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($bindings, ...$ids, ...$ids);

        return "({$quotedColumn} IN {$in} OR EXISTS (SELECT 1 FROM JSON_TABLE("
            . "CASE WHEN JSON_VALID({$quotedColumn}) THEN {$quotedColumn} ELSE '[]' END, '\$[*]'"
            . " COLUMNS(v VARCHAR(191) PATH '\$')) AS held WHERE held.v IN {$in}))";
    }
}
