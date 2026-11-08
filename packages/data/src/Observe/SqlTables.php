<?php

declare(strict_types=1);

namespace Preflow\Data\Observe;

/**
 * The tables a raw SQL statement names, read conservatively: every name after FROM, JOIN,
 * UPDATE, INTO and TABLE (DELETE FROM is FROM), with comma lists (`FROM a, b`, `UPDATE a, b`; every
 * comma of a FROM clause outside parentheses starts a table reference, `FROM a JOIN b ON 1, c` too),
 * quotes (`backticks`, "double", [brackets]) stripped, a schema prefix (`db.t`) dropped and the
 * name lowercased. Tables inside subqueries count: their FROM and JOIN are read like any other.
 * Strings and comments are skipped, so a FROM inside a literal is not a table.
 *
 * of() is null when it cannot be sure: a common table expression (WITH), a statement that is not
 * plain SQL on tables (CALL, EXEC, DO, LOAD, HANDLER...), or a FROM/JOIN/UPDATE/INTO it cannot read.
 * A view is reported under its own name: what it reads is not known here.
 */
final class SqlTables
{
    /** First words of statements whose tables are read from the text. */
    private const array KNOWN = [
        'SELECT', 'INSERT', 'REPLACE', 'UPDATE', 'DELETE', 'TRUNCATE', 'CREATE', 'ALTER', 'DROP',
        'VALUES', 'EXPLAIN', 'SHOW', 'DESCRIBE', 'DESC', 'PRAGMA', 'SET', 'BEGIN', 'START', 'COMMIT',
        'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'ANALYZE', 'OPTIMIZE', 'VACUUM', 'LOCK', 'UNLOCK', '(',
    ];

    /** First words of statements that change nothing (raw() run with them stays a read). */
    private const array READING = ['SELECT', 'VALUES', 'EXPLAIN', 'SHOW', 'DESCRIBE', 'DESC', '('];

    /** Words that end a table reference (never a table or an alias). */
    private const array STOP = [
        'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'NATURAL', 'FULL', 'STRAIGHT_JOIN',
        'ON', 'USING', 'SET', 'GROUP', 'ORDER', 'LIMIT', 'OFFSET', 'HAVING', 'UNION', 'EXCEPT', 'INTERSECT',
        'VALUES', 'VALUE', 'SELECT', 'WINDOW', 'FOR', 'USE', 'FORCE', 'IGNORE', 'INDEXED', 'NOT', 'AS',
        'DEFAULT', 'RETURNING', 'PARTITION', 'LOCK', 'INTO', 'FROM', 'UPDATE', 'DELETE', 'INSERT',
        'DUPLICATE', 'CONFLICT', 'DO', 'IF', 'EXISTS', 'TABLE', 'LATERAL', 'ONLY', 'LOW_PRIORITY', 'QUICK',
        'HIGH_PRIORITY', 'DELAYED', 'OR', 'AND', 'ROLLBACK', 'ABORT', 'FAIL', 'REPLACE', 'TEMPORARY',
        'TEMP', 'UNIQUE', 'INDEX', 'VIEW', 'TRIGGER', 'ADD', 'RENAME', 'COLUMN', 'MODIFY', 'CHANGE',
    ];

    /** First words of statements that change no data: a raw write of them invalidates nothing. */
    private const array NO_DATA = [
        'SELECT', 'VALUES', 'EXPLAIN', 'SHOW', 'DESCRIBE', 'DESC', 'PRAGMA', 'SET', 'BEGIN', 'START',
        'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'LOCK', 'UNLOCK', 'ANALYZE', 'OPTIMIZE', 'VACUUM', '(',
    ];

    /** First words of statements that change rows: one that names no table is unsure. */
    private const array DML = ['INSERT', 'REPLACE', 'UPDATE', 'DELETE', 'TRUNCATE'];

    /** MySQL and SQLite words between INSERT/REPLACE and the table (or INTO). */
    private const array INSERT_MODIFIERS = ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE', 'OR', 'ROLLBACK', 'ABORT', 'FAIL', 'REPLACE'];

    /**
     * The tables of every statement in $sql (several separated by ";" are read one by one).
     *
     * @return list<string>|null the tables (lowercased, each once, in order), [] when it names
     *         none (SELECT 1, SET, PRAGMA), null when unsure: also an INSERT, REPLACE, UPDATE,
     *         DELETE or TRUNCATE whose table it cannot find, and any quoted text with a backslash
     *         (an escape in MySQL, a plain character in SQLite: where it ends depends on the dialect)
     */
    public static function of(string $sql): ?array
    {
        $unsure = false;
        $tokens = self::tokens($sql, $unsure);
        if ($unsure) {
            return null;
        }
        $tables = [];
        foreach (self::statements($tokens) as $statement) {
            $read = self::tablesOf($statement);
            if ($read === null) {
                return null;
            }
            foreach ($read as $table) {
                $tables[$table] = true;
            }
        }

        return array_keys($tables);
    }

    /**
     * Whether every statement in $sql leaves the data as it is (SELECT, SET, PRAGMA, BEGIN...).
     * A raw write that names no table and is not one of these is unsure.
     */
    public static function changesNothing(string $sql): bool
    {
        $unsure = false;
        foreach (self::statements(self::tokens($sql, $unsure)) as $statement) {
            $first = strtoupper($statement[0][1]);
            if (!in_array($first, self::NO_DATA, true)
                || (in_array($first, self::READING, true) && self::writes(self::text($statement)))) {
                return false;
            }
        }

        return !$unsure;
    }

    /**
     * Whether the statement may change data: anything but a plain read (SELECT, VALUES, SHOW,
     * EXPLAIN, or WITH ... SELECT), in any of its statements. INSERT, UPDATE, DELETE or REPLACE
     * anywhere in a read (a data-modifying CTE) and SELECT ... INTO count as writes; unknown
     * statements do too.
     */
    public static function writes(string $sql): bool
    {
        if (preg_match('~/\*M?!~', $sql) === 1) {
            return true; // an executable comment may hold any statement
        }
        $unsure = false;
        foreach (self::statements(self::tokens($sql, $unsure)) as $tokens) {
            $first = strtoupper($tokens[0][1]);
            if ($first !== 'WITH' && !in_array($first, self::READING, true)) {
                return true;
            }
            foreach ($tokens as $i => [$kind, $text]) {
                if ($kind !== 'word') {
                    continue;
                }
                $word = strtoupper($text);
                $previous = $i > 0 ? strtoupper($tokens[$i - 1][1]) : '';
                $call = ($tokens[$i + 1][1] ?? null) === '(';
                if ($word === 'INTO' || in_array($word, ['INSERT', 'DELETE', 'TRUNCATE', 'DROP', 'ALTER', 'CREATE', 'RENAME'], true)
                    || ($word === 'REPLACE' && !$call)
                    || ($word === 'UPDATE' && !in_array($previous, ['FOR', 'KEY', 'DO'], true))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{0: string, 1: string}> $tokens one statement
     * @return list<string>|null
     */
    private static function tablesOf(array $tokens): ?array
    {
        $first = strtoupper($tokens[0][1]);
        if (!in_array($first, self::KNOWN, true)) {
            return null;
        }

        $tables = [];
        if ($first === 'INSERT' || $first === 'REPLACE') {
            // MySQL's INTO is optional: INSERT [IGNORE] t (...) VALUES, INSERT t SET ..., REPLACE t VALUES.
            $j = 1;
            while (isset($tokens[$j]) && $tokens[$j][0] === 'word' && in_array(strtoupper($tokens[$j][1]), self::INSERT_MODIFIERS, true)) {
                $j++;
            }
            if (!isset($tokens[$j]) || $tokens[$j][0] !== 'word' || strtoupper($tokens[$j][1]) !== 'INTO') {
                $read = self::references($tokens, $j, 'INTO');
                if ($read === null) {
                    return null;
                }
                foreach ($read as $table) {
                    $tables[$table] = true;
                }
            }
        }

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind !== 'word') {
                continue;
            }
            $word = strtoupper($text);
            if ($word === 'WITH') {
                // A common table expression (WITH name AS ...), not WITH ROLLUP / WITH (index hints).
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && ($next[0] === 'name' || ($next[0] === 'word' && !in_array(strtoupper($next[1]), ['ROLLUP', 'CHECK', 'CASCADED', 'LOCAL', 'TIES', 'NOLOCK', 'READ', 'CONSISTENT', 'NO', 'GRANT', 'ORDINALITY', 'TIME', 'PARSER', 'SYSTEM'], true)))) {
                    return null;
                }
                continue;
            }
            if (!in_array($word, ['FROM', 'JOIN', 'STRAIGHT_JOIN', 'UPDATE', 'INTO', 'TABLE', 'TRUNCATE'], true)) {
                continue;
            }
            $previous = $i > 0 ? strtoupper($tokens[$i - 1][1]) : '';
            // ON DUPLICATE KEY UPDATE, FOR UPDATE, DO UPDATE, SELECT ... FOR UPDATE: no table follows.
            if ($word === 'UPDATE' && in_array($previous, ['KEY', 'FOR', 'DO'], true)) {
                continue;
            }
            $j = $i + 1;
            // TABLE after CREATE/ALTER/DROP/TRUNCATE/LOCK: skip IF [NOT] EXISTS.
            while (isset($tokens[$j]) && $tokens[$j][0] === 'word' && in_array(strtoupper($tokens[$j][1]), ['IF', 'NOT', 'EXISTS', 'ONLY', 'LOW_PRIORITY', 'IGNORE', 'LATERAL', 'TABLE'], true)) {
                $j++;
            }
            $read = self::references($tokens, $j, $word === 'STRAIGHT_JOIN' ? 'JOIN' : $word);
            if ($read === null) {
                return null;
            }
            foreach ($read as $table) {
                $tables[$table] = true;
            }
        }

        // After FROM (and UPDATE's table list), every comma at the clause's own depth starts
        // another table reference, also one after a JOIN ... ON or USING (FROM a JOIN b ON 1, c).
        $read = self::afterCommas($tokens);
        if ($read === null) {
            return null;
        }
        foreach ($read as $table) {
            $tables[$table] = true;
        }

        return $tables === [] && in_array($first, self::DML, true) ? null : array_keys($tables);
    }

    /** Words that end a table-reference clause (FROM ..., UPDATE ...) at its depth. */
    private const array CLAUSE_END = [
        'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'OFFSET', 'FETCH', 'HAVING', 'UNION', 'EXCEPT', 'INTERSECT',
        'WINDOW', 'QUALIFY', 'RETURNING', 'SET', 'DO', 'DUPLICATE', 'CONFLICT', 'FOR', 'LOCK', 'VALUES',
        'SELECT', 'INTO', 'PROCEDURE',
    ];

    /**
     * The tables after each top-level comma of a FROM clause or of UPDATE's table list (the
     * comma not inside parentheses): one there starts a new table reference, wherever it
     * follows (a join condition too).
     *
     * @param list<array{0: string, 1: string}> $tokens one statement
     * @return list<string>|null
     */
    private static function afterCommas(array $tokens): ?array
    {
        $tables = [];
        $depth = 0;
        $open = []; // depth => true inside a table-reference clause
        foreach ($tokens as $i => [$kind, $text]) {
            if ($kind === 'punct') {
                if ($text === '(') {
                    $depth++;
                } elseif ($text === ')') {
                    unset($open[$depth]);
                    $depth = max(0, $depth - 1);
                } elseif ($text === ',' && isset($open[$depth])) {
                    $read = self::references($tokens, $i + 1, 'FROM');
                    if ($read === null) {
                        return null;
                    }
                    array_push($tables, ...$read);
                }

                continue;
            }
            if ($kind !== 'word') {
                continue;
            }
            $word = strtoupper($text);
            $previous = $i > 0 ? strtoupper($tokens[$i - 1][1]) : '';
            if ($word === 'FROM' || ($word === 'UPDATE' && !in_array($previous, ['KEY', 'FOR', 'DO'], true))) {
                $open[$depth] = true;
            } elseif (in_array($word, self::CLAUSE_END, true)) {
                unset($open[$depth]);
            }
        }

        return $tables;
    }

    /**
     * The statements of a token list, split at ";" (empty ones dropped).
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return list<non-empty-list<array{0: string, 1: string}>>
     */
    private static function statements(array $tokens): array
    {
        $statements = [];
        $current = [];
        foreach ($tokens as $token) {
            if ($token[0] === 'punct' && $token[1] === ';') {
                if ($current !== []) {
                    $statements[] = $current;
                }
                $current = [];
                continue;
            }
            $current[] = $token;
        }
        if ($current !== []) {
            $statements[] = $current;
        }

        return $statements;
    }

    /** @param list<array{0: string, 1: string}> $tokens a statement as text again (for writes()) */
    private static function text(array $tokens): string
    {
        return implode(' ', array_map(static fn (array $t): string => match ($t[0]) {
            'string' => "''",
            'name' => '`' . str_replace('`', '``', $t[1]) . '`',
            default => $t[1],
        }, $tokens));
    }

    /**
     * The tables from $j on: one reference, or a comma list of them, each with an optional alias.
     * After FROM and JOIN, a subquery and a table-valued function (json_each(...)) are skipped
     * (a subquery's own FROMs are read by of()); a parenthesised join group is unsure. After
     * UPDATE and INTO a name must follow.
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @return list<string>|null
     */
    private static function references(array $tokens, int $j, string $keyword): ?array
    {
        $tables = [];
        $sources = in_array($keyword, ['FROM', 'JOIN'], true);
        while (true) {
            $token = $tokens[$j] ?? null;
            if ($token === null) {
                return $tables === [] && !$sources && $keyword !== 'TABLE' ? null : $tables;
            }
            if ($token[0] === 'punct' && $token[1] === '(') {
                $inner = strtoupper($tokens[$j + 1][1] ?? '');
                if (!$sources || !in_array($inner, ['SELECT', 'VALUES', 'WITH'], true)) {
                    return null; // a join group, or no name where one must be
                }
                $j = self::after($tokens, $j);
            } elseif ($token[0] === 'word' && in_array(strtoupper($token[1]), self::STOP, true)) {
                // A keyword where a table could be: none here (EXTRACT(YEAR FROM x) reads x: harmless).
                return $tables === [] && in_array($keyword, ['UPDATE', 'INTO'], true) ? null : $tables;
            } elseif ($token[0] !== 'word' && $token[0] !== 'name') {
                // A number, string or operator (TRIM(x FROM 'a'), SUBSTRING(s FROM 2)): no table.
                return $sources || $keyword === 'TABLE' ? $tables : null;
            } else {
                // A dotted name (schema.table): the table is the last part.
                $name = $token[1];
                while (($tokens[$j + 1][1] ?? null) === '.' && in_array($tokens[$j + 2][0] ?? null, ['word', 'name'], true)) {
                    $j += 2;
                    $name = $tokens[$j][1];
                }
                $j++;
                if ($sources && $token[0] === 'word' && ($tokens[$j][1] ?? null) === '(') {
                    $j = self::after($tokens, $j); // a table-valued function: reads no table itself
                } else {
                    $tables[] = strtolower($name);
                }
            }
            // An alias: [AS] name.
            if (isset($tokens[$j]) && $tokens[$j][0] === 'word' && strtoupper($tokens[$j][1]) === 'AS') {
                $j++;
            }
            if (isset($tokens[$j]) && ($tokens[$j][0] === 'name' || ($tokens[$j][0] === 'word' && !in_array(strtoupper($tokens[$j][1]), self::STOP, true)))) {
                $j++;
            }
            $j = self::afterHints($tokens, $j);
            if (($tokens[$j][1] ?? null) !== ',' || $keyword === 'INTO') {
                return $tables;
            }
            $j++; // FROM a, b
        }
    }

    /**
     * The index after any index hints at $j: MySQL's USE|FORCE|IGNORE INDEX|KEY [FOR JOIN|ORDER
     * BY|GROUP BY] (...), SQLite's INDEXED BY name and NOT INDEXED.
     *
     * @param list<array{0: string, 1: string}> $tokens
     */
    private static function afterHints(array $tokens, int $j): int
    {
        $word = static fn (int $k): string => ($tokens[$k][0] ?? null) === 'word' ? strtoupper($tokens[$k][1]) : '';
        while (true) {
            if (in_array($word($j), ['USE', 'FORCE', 'IGNORE'], true) && in_array($word($j + 1), ['INDEX', 'KEY'], true)) {
                $j += 2;
                if ($word($j) === 'FOR') {
                    $j += in_array($word($j + 1), ['ORDER', 'GROUP'], true) ? 3 : 2;
                }
                if (($tokens[$j][1] ?? null) === '(') {
                    $j = self::after($tokens, $j);
                }
            } elseif ($word($j) === 'INDEXED' && $word($j + 1) === 'BY') {
                $j += 3;
            } elseif ($word($j) === 'NOT' && $word($j + 1) === 'INDEXED') {
                $j += 2;
            } else {
                return $j;
            }
        }
    }

    /**
     * The index after the parenthesis that closes the one at $open.
     *
     * @param list<array{0: string, 1: string}> $tokens
     */
    private static function after(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($k = $open; $k < $count; $k++) {
            if ($tokens[$k][0] !== 'punct') {
                continue;
            }
            if ($tokens[$k][1] === '(') {
                $depth++;
            } elseif ($tokens[$k][1] === ')' && --$depth === 0) {
                return $k + 1;
            }
        }

        return $count;
    }

    /**
     * Words (bare identifiers and keywords), names (quoted identifiers, unquoted), strings,
     * numbers and punctuation; whitespace and comments dropped.
     *
     * @param bool $unsure set when quoted text holds a backslash (where it ends depends on the dialect)
     * @return list<array{0: 'word'|'name'|'string'|'number'|'punct', 1: string}>
     */
    private static function tokens(string $sql, bool &$unsure): array
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $c = $sql[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-' || $c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*' && (($sql[$i + 2] ?? '') === '!' || substr($sql, $i + 2, 2) === 'M!')) {
                // A MySQL/MariaDB executable comment (/*!40000 ... */, /*M! ... */): the server
                // runs it, so it may hold any statement.
                $unsure = true;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                // A plain comment, and an optimizer hint (/*+ ... */): a hint only names tables the
                // statement names itself, and read as words it would hide UPDATE /*+ BKA(t) */ t.
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }
            if ($c === "'") {
                $end = self::closing($sql, $i, "'");
                $unsure = $unsure || str_contains(substr($sql, $i, $end - $i), '\\');
                $i = $end;
                $tokens[] = ['string', ''];
                continue;
            }
            if ($c === '`' || $c === '"' || $c === '[') {
                $close = $c === '[' ? ']' : $c;
                $end = self::closing($sql, $i, $close);
                $unsure = $unsure || ($c === '"' && str_contains(substr($sql, $i, $end - $i), '\\'));
                $tokens[] = ['name', str_replace($close . $close, $close, substr($sql, $i + 1, $end - $i - 2))];
                $i = $end;
                continue;
            }
            if (ctype_alpha($c) || $c === '_' || ord($c) >= 0x80) {
                $start = $i;
                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '$' || ord($sql[$i]) >= 0x80)) {
                    $i++;
                }
                $tokens[] = ['word', substr($sql, $start, $i - $start)];
                continue;
            }
            if (ctype_digit($c)) {
                $start = $i;
                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '.')) {
                    $i++;
                }
                $tokens[] = ['number', substr($sql, $start, $i - $start)];
                continue;
            }
            $tokens[] = ['punct', $c];
            $i++;
        }

        return $tokens;
    }

    /** The offset after the quote that closes the one at $start (a doubled quote is escaped). */
    private static function closing(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        $i = $start + 1;
        while ($i < $length) {
            if ($quote === "'" && $sql[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                    continue;
                }

                return $i + 1;
            }
            $i++;
        }

        return $length;
    }
}
