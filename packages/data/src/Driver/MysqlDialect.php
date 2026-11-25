<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\UniqueViolation;

final class MysqlDialect implements Dialect, PatternMatching, TextComparison
{
    /**
     * The text rule's collations (ignore case, respect accents, NO PAD), in order of preference:
     * MariaDB 10.10+'s UCA 14 one, MySQL 8's UCA 9 one (MariaDB 11.4.5+ knows it too, as an
     * alias). Not utf8mb4_uca1400_as_ci: it is PAD SPACE, so 'abc' would equal 'abc '. The text
     * fields' columns (MysqlGrammar::textColumnType()) and textValue() use the first one the
     * server accepts (MysqlGrammar::on() probes it).
     *
     * @since 0.78
     */
    public const array MATCH_COLLATIONS = ['utf8mb4_uca1400_nopad_as_ci', 'utf8mb4_0900_as_ci'];

    /**
     * The matching rule's collations (ignore case and accents), in order of preference: MariaDB
     * 10.10+'s UCA 14 one, MySQL 8's UCA 9 one (MariaDB 11.4.5+ knows it too, as an alias). LIKE,
     * NOT LIKE, CONTAINS and search() compare in the first one the server accepts
     * (MysqlGrammar::searchCollation()).
     *
     * @since 0.78.1
     */
    public const array SEARCH_COLLATIONS = ['utf8mb4_uca1400_nopad_ai_ci', 'utf8mb4_0900_ai_ci'];

    /** @var \Closure(): ?string|null resolves the collation on the first text comparison */
    private ?\Closure $resolveCollation = null;

    private ?string $matchCollation = null;

    /** @var \Closure(): ?string|null resolves the search collation on the first LIKE */
    private ?\Closure $resolveSearchCollation = null;

    private ?string $searchCollation = null;

    /**
     * @param \Closure(): ?string|string|null $matchCollation The collation textValue() compares
     *     with (one of MATCH_COLLATIONS the server has); null for none (textValue() compares
     *     exactly); a closure naming it (or null), called once, on first use.
     * @param \Closure(): ?string|string|null $searchCollation The collation LIKE compares with
     *     (one of SEARCH_COLLATIONS the server has, @since 0.78.1); null for none (LIKE then
     *     compares in $matchCollation, without one either it lowers both sides, see likeSql()); a
     *     closure naming it (or null), called once, on first use.
     */
    public function __construct(\Closure|string|null $matchCollation = null, \Closure|string|null $searchCollation = null)
    {
        if ($matchCollation instanceof \Closure) {
            $this->resolveCollation = $matchCollation;
        } else {
            $this->matchCollation = self::collationName($matchCollation);
        }
        if ($searchCollation instanceof \Closure) {
            $this->resolveSearchCollation = $searchCollation;
        } else {
            $this->searchCollation = self::collationName($searchCollation);
        }
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
     * a number column compared with '' too, so '' is checked by the length of the text form (a
     * comparison with '' would take spaces for '' under a PAD SPACE collation). The rule is
     * Condition::wholeNumber()'s:
     * - A number column (CHARSET() 'binary': DOUBLE, BIGINT, DECIMAL) is cut by TRUNCATE(); its
     *   text form can be '1e15' (MariaDB writes a DOUBLE from 1e15 so), which the text rule would
     *   read as 0. A binary string column holding such text reads as a number too.
     * - Text is a number only as `-?digits` or `-?digits.digits`: `$` also matches before a final
     *   line break (ICU and PCRE), so a second check refuses any other character (since 0.90).
     *   The whole part is the digits before the dot (SUBSTRING_INDEX), not a rounded DECIMAL.
     * Beyond a 64-bit integer both clamp to its bounds instead of failing the UPDATE, as SQLite's
     * CAST and Condition::wholeNumber() do (PHP's own (int) of a float would wrap).
     */
    public function wholeNumber(string $expression): string
    {
        $e = $expression;
        $text = "CAST({$e} AS CHAR)";
        $min = '(-9223372036854775807 - 1)';
        $max = '9223372036854775807';
        $digits = "TRIM(LEADING '0' FROM SUBSTRING_INDEX(TRIM(LEADING '-' FROM {$text}), '.', 1))";

        return "(CASE WHEN {$e} IS NULL OR LENGTH({$text}) = 0 THEN NULL"
            . " WHEN CHARSET({$e}) = 'binary' AND {$text} REGEXP '^-?[0-9]+([.][0-9]+)?(e[-+]?[0-9]+)?$' AND {$text} NOT REGEXP '[^-+.0-9e]' THEN"
            . " (CASE WHEN {$e} >= {$max} THEN {$max} WHEN {$e} <= {$min} THEN {$min} ELSE CAST(TRUNCATE({$e}, 0) AS SIGNED) END)"
            . " WHEN {$text} REGEXP '^-?[0-9]+([.][0-9]*)?$' AND {$text} NOT REGEXP '[^-.0-9]' THEN"
            . " (CASE WHEN LENGTH({$digits}) > 19 OR (LENGTH({$digits}) = 19 AND {$digits} > '{$max}')"
            . " THEN (CASE WHEN {$text} LIKE '-%' THEN {$min} ELSE {$max} END)"
            . " ELSE CAST(SUBSTRING_INDEX({$text}, '.', 1) AS SIGNED) END)"
            . ' ELSE 0 END)';
    }

    /**
     * The plain operator, used only by a compiler that does not ask likeSql() (PatternMatching).
     * It does not follow the matching rule: it compares in the column's collation, which for a
     * text field respects accents. Only likeSql() ignores them.
     */
    public function likeOperator(): string
    {
        return 'LIKE';
    }

    /**
     * LIKE under the matching rule (case, accents and width ignored). With a search collation
     * (SEARCH_COLLATIONS) the column is compared in it; the CAST lets a number column or one of
     * another character set take the COLLATE (as in exactText()), which also means a prefix
     * pattern does not use an index. A server without one but with the text rule's collation
     * (MATCH_COLLATIONS) compares in that one, so accents count; one without either lowers both
     * sides and compares by code point (utf8mb4_bin). The data README's "Text matching" says
     * where those two differ from the rule.
     */
    public function likeSql(string $column, string $pattern, bool $not = false, ?string $escape = null): string
    {
        $like = ($not ? ' NOT' : '') . ' LIKE ';
        $escapeSql = $escape === null ? '' : " ESCAPE '" . str_replace("'", "''", $escape) . "'";
        $collation = $this->searchCollation() ?? $this->matchCollation();
        if ($collation !== null) {
            return "(CAST({$column} AS CHAR CHARACTER SET utf8mb4) COLLATE {$collation}){$like}?{$escapeSql}";
        }

        return "LOWER(CAST({$column} AS CHAR CHARACTER SET utf8mb4)) COLLATE utf8mb4_bin{$like}LOWER(CONVERT(? USING utf8mb4)) COLLATE utf8mb4_bin{$escapeSql}";
    }

    /**
     * A value compared by the text rule: cast to utf8mb4 (a JSON value, a number) in the rule's
     * collation. Without one (a server older than MariaDB 10.10 / MySQL 8) exactly, as the
     * columns of text fields are there (exactText()).
     */
    public function textValue(string $expression): string
    {
        $collation = $this->matchCollation();

        return $collation === null ? $this->exactText($expression) : "(CAST({$expression} AS CHAR CHARACTER SET utf8mb4) COLLATE {$collation})";
    }

    /**
     * By the value's weights in its collation (WEIGHT_STRING()). MariaDB (checked on 13.0) sorts a
     * TEXT or LONGTEXT value (and a CAST one) by its first-level weights only, so "äpfel" and
     * "Apfel" come in storage order, where its "<" and a VARCHAR column put "Apfel" first; the
     * weight string holds every level. ORDER BY then cannot use an index on the column.
     */
    public function textOrder(string $expression): string
    {
        return "WEIGHT_STRING({$expression})";
    }

    /** The text rule's collation textValue() compares in, or null for none (a closure resolved once). */
    private function matchCollation(): ?string
    {
        if ($this->resolveCollation !== null) {
            $this->matchCollation = self::collationName(($this->resolveCollation)()); // a failure is asked again on the next use
            $this->resolveCollation = null;
        }

        return $this->matchCollation;
    }

    /** The collation LIKE compares in, or null for none (a closure resolved once). */
    private function searchCollation(): ?string
    {
        if ($this->resolveSearchCollation !== null) {
            $this->searchCollation = self::collationName(($this->resolveSearchCollation)()); // a failure is asked again on the next LIKE
            $this->resolveSearchCollation = null;
        }

        return $this->searchCollation;
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
     * The columns of text fields ignore case; utf8mb4_bin compares code points. The cast first
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
     * (MariaDB 10.6+, MySQL 8): 2 bindings per id. A list's members compare exactly
     * (utf8mb4_bin; the connection's default collation would ignore case); the plain value as the
     * column (or the expression given) compares.
     */
    public function jsonArrayHasAny(string $quotedColumn, array $ids, array &$bindings): string
    {
        $ids = array_values($ids);
        $in = '(' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($bindings, ...$ids, ...$ids);

        return "({$quotedColumn} IN {$in} OR EXISTS (SELECT 1 FROM JSON_TABLE("
            . "CASE WHEN JSON_VALID({$quotedColumn}) THEN {$quotedColumn} ELSE '[]' END, '\$[*]'"
            . " COLUMNS(v VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin PATH '\$')) AS held WHERE held.v IN {$in}))";
    }
}
