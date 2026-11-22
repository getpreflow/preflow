<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;
use Preflow\Data\Text\TextMatch;

/**
 * The DDL text here is pinned by SqliteDdlCharacterisationTest: existing sites must not see
 * changes. Since 0.78 a bound grammar (on(), as Grammars::for() returns it) gives the columns of
 * text fields and a migration's string() and text() the collation preflow_text
 * (SqliteDialect::TEXT_COLLATION): they compare, sort, group and keep a unique index by the text
 * rule. Every other column compares by bytes (BINARY), as before.
 */
final class SqliteGrammar implements SchemaGrammar, ConvertsCollations, ClassifiesIdColumns
{
    use RecordIndexNames;

    /** The collation text columns name (on()), null for none. */
    private ?string $textCollation = null;

    /** @var \WeakMap<\PDO, true>|null the connections found to have the collation */
    private static ?\WeakMap $prepared = null;

    /** The collations a table rebuild carries over; any other refuses it. */
    private const array KEPT_COLLATIONS = ['binary', SqliteDialect::TEXT_COLLATION];

    /** @param (\Closure(\PDO): string)|null $version reports the server's SQLite version (default: sqlite_version()); injectable for tests */
    public function __construct(private readonly ?\Closure $version = null)
    {
    }

    /**
     * Bound to preflow_text. The collation must be registered on the connection
     * (SqliteDialect::prepare(), which SqliteDriver calls): a table whose columns declare it
     * cannot be compared or indexed on a connection without it.
     *
     * @throws \LogicException when the connection lacks the collation
     */
    public function on(\PDO $pdo): static
    {
        self::$prepared ??= new \WeakMap();
        if (!isset(self::$prepared[$pdo])) {
            $list = $pdo->query('PRAGMA collation_list');
            if ($list === false) {
                throw new \RuntimeException('Reading the collations of the SQLite connection failed: ' . ($pdo->errorInfo()[2] ?? 'unknown error'));
            }
            $registered = array_map(static fn (array $row): string => strtolower((string) $row['name']), $list->fetchAll(\PDO::FETCH_ASSOC));
            if (!in_array(SqliteDialect::TEXT_COLLATION, $registered, true)) {
                throw new \LogicException('This SQLite connection has no collation ' . SqliteDialect::TEXT_COLLATION
                    . ', which the columns of text fields declare: call SqliteDialect::prepare() on it (SqliteDriver does).');
            }
            self::$prepared[$pdo] = true; // a collation cannot be unregistered
        }
        $bound = clone $this;
        $bound->textCollation = SqliteDialect::TEXT_COLLATION;

        return $bound;
    }

    public function textCollation(): ?string
    {
        return $this->textCollation;
    }

    public function textColumnType(ColumnKind $kind, ?int $length = null): string
    {
        $type = $this->recordColumnType($kind, $length);

        return $this->textCollation !== null && in_array($kind, [ColumnKind::Text, ColumnKind::Short], true) ? $type . ' COLLATE ' . $this->textCollation : $type;
    }

    public function textType(string $kind, ?int $length = null): string
    {
        $type = $this->columnType($kind, $length);

        return $this->textCollation !== null && in_array($kind, ['string', 'text'], true) ? $type . ' COLLATE ' . $this->textCollation : $type;
    }

    /** SQLite compares a column without COLLATE by bytes. */
    public function exactType(string $kind, ?int $length = null): string
    {
        return $this->columnType($kind, $length);
    }

    /**
     * Read from the table's CREATE statement (PRAGMA table_info has no collation): a column's
     * COLLATE as declared, else BINARY for one with text affinity (its type names CHAR, CLOB or
     * TEXT), else null (numbers, untyped).
     */
    public function columnCollations(\PDO $pdo, string $table): array
    {
        $declared = self::declaredCollations(self::createStatement($pdo, $table));
        $collations = [];
        foreach ($pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['name'];
            $collations[$name] = $declared[strtolower($name)]
                ?? (preg_match('/char|clob|text/i', (string) $row['type']) === 1 ? 'BINARY' : null);
        }

        return $collations;
    }

    /** One table rebuild (see convertColumn()) with each of the columns given its new type. */
    public function convertCollations(\PDO $pdo, string $table, array $types): void
    {
        if ($types === []) {
            return;
        }
        $this->rebuild($pdo, $table, array_map(static fn (string $type): array => [$type, null], $types), 'the collations of "' . $table . '"');
    }

    /** None: a column added without COLLATE compares exactly (BINARY), as a free-text one should. */
    public function tableCollation(\PDO $pdo, string $table): ?string
    {
        return null;
    }

    public function convertTableCollation(\PDO $pdo, string $table): void
    {
        throw new \LogicException('SQLite tables have no default collation.');
    }

    public function isTextCollation(?string $collation): bool
    {
        return $collation !== null && strtolower($collation) === SqliteDialect::TEXT_COLLATION;
    }

    public function exactCollation(): string
    {
        return 'BINARY';
    }

    public function isExactCollation(?string $collation): bool
    {
        return $collation !== null && strtoupper($collation) === 'BINARY';
    }

    /** The rule as "type COLLATE preflow_text"; exact as the bare type (SQLite compares a column without COLLATE by bytes). */
    public function withCollation(string $type, bool $text): string
    {
        return $text && $this->textCollation !== null ? $type . ' COLLATE ' . $this->textCollation : $type;
    }

    /** SQLite keeps every value as it is whatever the collation. */
    public function collationRefusal(string $type, ?string $collation): ?string
    {
        return null;
    }

    public function collate(string $expression, string $collation): string
    {
        return $expression . ' COLLATE ' . $this->quote($collation);
    }

    /** SQLite indexes whole values. */
    public function uniquePrefixes(\PDO $pdo, string $table): array
    {
        return [];
    }

    public function prefix(string $expression, int $length): string
    {
        return "substr({$expression}, 1, {$length})";
    }

    /** The single-column primary key, else rowid. */
    public function rowKey(\PDO $pdo, string $table): string
    {
        $keys = array_values(array_filter(
            $pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC),
            static fn (array $c): bool => (int) $c['pk'] > 0,
        ));

        return count($keys) === 1 ? (string) $keys[0]['name'] : 'rowid';
    }

    public function tables(\PDO $pdo): array
    {
        return array_map('strval', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** preflow_text is PHP's (TextMatch::compare()): its version, null when unbound. */
    public function textCollationVersion(): ?string
    {
        return $this->textCollation === null ? null : TextMatch::version();
    }

    /**
     * REINDEX of each index (automatic ones of UNIQUE constraints too) that has a column compared by
     * preflow_text (PRAGMA index_xinfo), each in its own savepoint: one that fails (a unique index
     * whose values are now equal) is rolled back to how it was and returned.
     */
    public function reindexText(\PDO $pdo): array
    {
        $tables = $this->tables($pdo);
        $failed = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            foreach ($pdo->query('PRAGMA index_list(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $index) {
                $name = (string) $index['name'];
                $columns = [];
                $text = false;
                foreach ($pdo->query('PRAGMA index_xinfo(' . $this->quote($name) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $part) {
                    if ((int) $part['key'] !== 1) {
                        continue;
                    }
                    $columns[] = (string) $part['name'];
                    $text = $text || strtolower((string) $part['coll']) === SqliteDialect::TEXT_COLLATION;
                }
                if (!$text) {
                    continue;
                }
                $pdo->exec('SAVEPOINT preflow_reindex');
                try {
                    $pdo->exec('REINDEX ' . $this->quote($name));
                    $pdo->exec('RELEASE preflow_reindex');
                } catch (\PDOException $e) {
                    $pdo->exec('ROLLBACK TO preflow_reindex');
                    $pdo->exec('RELEASE preflow_reindex');
                    $failed[] = ['table' => $table, 'index' => $name, 'columns' => $columns, 'error' => $e->getMessage()];
                }
            }
        }

        return $failed;
    }

    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`'; // as SqliteDialect::quoteIdentifier(): never read as a string
    }

    public function columnType(string $kind, ?int $length = null): string
    {
        return match ($kind) {
            'integer', 'boolean' => 'INTEGER',
            default => 'TEXT',
        };
    }

    public function createTable(string $table, array $columns): string
    {
        $parts = [];

        foreach ($columns as $col) {
            $text = !$col['primary'] && !($col['exact'] ?? false);
            $def = $this->quote($col['name']) . ' ' . ($text ? $this->textType($col['kind'], $col['length']) : $this->columnType($col['kind'], $col['length']));

            if ($col['primary']) {
                $def .= ' PRIMARY KEY'; // an INTEGER one (increments()) numbers itself; AUTOINCREMENT is left out, rebuild() refuses it
            }

            if (!$col['nullable'] && !$col['primary']) {
                $def .= ' NOT NULL';
            }

            $parts[] = $def;
        }

        return 'CREATE TABLE ' . $this->quote($table) . " (\n    " . implode(",\n    ", $parts) . "\n)";
    }

    public function createRecordTable(string $table, string $idField, array $columns): string
    {
        $defs = array_map(fn (ColumnSpec $c): string => $this->quote($c->name) . ' '
            . ($c->text ? $this->textColumnType($c->kind, $c->length) : $this->recordColumnType($c->kind, $c->length)), $columns);

        return 'CREATE TABLE ' . $this->quote($table) . ' (' . $this->quote($idField) . ' TEXT PRIMARY KEY, '
            . implode(', ', $defs) . ')';
    }

    /**
     * NUMERIC, not REAL: '12' stays the integer 12 (REAL would read back 12.0), '12.5' becomes 12.5,
     * and numbers sort numerically. SQLite has no bounded strings: Short is TEXT.
     */
    public function recordColumnType(ColumnKind $kind, ?int $length = null): string
    {
        return match ($kind) {
            ColumnKind::Number => 'NUMERIC',
            ColumnKind::Boolean => 'INTEGER',
            ColumnKind::Text, ColumnKind::Short, ColumnKind::Date => 'TEXT',
        };
    }

    public function createRecordIndex(string $name, string $table, array $columns, bool $unique, array $columnTypes = [], array $lengths = []): string
    {
        return 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->quote($name) . ' ON ' . $this->quote($table)
            . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ')';
    }

    public function createIndex(string $table, string $column): string
    {
        return 'CREATE INDEX ' . $this->quote("idx_{$table}_{$column}")
            . ' ON ' . $this->quote($table) . ' (' . $this->quote($column) . ')';
    }

    public function dropTable(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quote($table);
    }

    public function hasTable(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    public function columns(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * SQLite's column affinity rules: a type containing "int" is an integer column (Int); one
     * containing "char", "clob" or "text" a text column (String); any other (blob, real, numeric,
     * none) holds no one kind, so null.
     *
     * @since 0.82.0
     */
    public function idKindOfColumn(string $type): ?\Preflow\Data\IdKind
    {
        $type = strtolower($type);

        return match (true) {
            str_contains($type, 'int') => \Preflow\Data\IdKind::Int,
            str_contains($type, 'char'), str_contains($type, 'clob'), str_contains($type, 'text') => \Preflow\Data\IdKind::String,
            default => null,
        };
    }

    public function columnTypes(\PDO $pdo, string $table): array
    {
        $types = [];
        foreach ($pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $types[(string) $row['name']] = strtolower((string) $row['type']);
        }

        return $types;
    }

    public function indexes(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA index_list(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function uniqueIndexes(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA index_list(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $row): string => (string) $row['name'], array_filter($rows, static fn (array $row): bool => (int) $row['unique'] === 1)));
    }

    public function indexColumns(\PDO $pdo, string $table): array
    {
        $result = [];
        foreach ($this->indexes($pdo, $table) as $name) {
            $rows = $pdo->query('PRAGMA index_info(' . $this->quote($name) . ')')->fetchAll(\PDO::FETCH_ASSOC);
            $result[$name] = array_map(static fn (array $row): string => (string) $row['name'], $rows);
        }

        return $result;
    }

    public function dropIndex(string $table, string $index): string
    {
        if (str_starts_with(strtolower($index), 'sqlite_autoindex_')) {
            throw new \LogicException("Cannot drop the index \"{$index}\": SQLite creates it for a PRIMARY KEY or UNIQUE constraint of the table and cannot drop it.");
        }

        return 'DROP INDEX ' . $this->quote($index);
    }

    public function assertCanDropColumn(\PDO $pdo): void
    {
        $version = $this->version !== null ? ($this->version)($pdo) : (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
        if (version_compare($version, '3.35.0', '<')) {
            throw new \LogicException("dropColumn needs SQLite 3.35 or newer; this server has {$version}.");
        }
    }

    public function addColumn(string $table, string $column, ?string $type = null): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->quote($column) . ' ' . ($type ?? 'TEXT');
    }

    public function renameColumn(string $table, string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' RENAME COLUMN ' . $this->quote($from) . ' TO ' . $this->quote($to);
    }

    public function copyColumn(string $table, string $from, string $to): string
    {
        return 'UPDATE ' . $this->quote($table) . ' SET ' . $this->quote($to) . ' = ' . $this->quote($from) . ' WHERE ' . $this->quote($to) . ' IS NULL';
    }

    public function dropColumn(string $table, string $column): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' DROP COLUMN ' . $this->quote($column);
    }

    /**
     * SQLite cannot change a column, so the table is rebuilt (sqlite.org/lang_altertable.html, "other
     * kinds of table schema changes"): a new table with every column as PRAGMA table_info reports it
     * (type, NOT NULL, DEFAULT, primary key) and its collation (columnCollations()), with $column
     * changed, the rows copied, the old table dropped, the new one renamed, its indexes and triggers
     * made again from their stored SQL (so an index over a preflow_text column applies the rule);
     * views keep naming the table. All in one savepoint, so it joins a transaction the caller has
     * open. Foreign keys are switched off for the rebuild (and checked before it commits) when no
     * transaction is open, as dropping the old table would otherwise act on the rows that refer to
     * it; with one open they must already be off. Refuses (LogicException, nothing changed) a table
     * whose CREATE statement has what table_info cannot carry over: REFERENCES, CHECK, UNIQUE,
     * CONSTRAINT, AUTOINCREMENT, a generated column, WITHOUT ROWID or STRICT, or a COLLATE other
     * than preflow_text and BINARY.
     */
    public function convertColumn(\PDO $pdo, string $table, string $column, string $type, ?bool $nullable): void
    {
        $this->rebuild($pdo, $table, [$column => [$type, $nullable]], "\"{$table}.{$column}\"");
    }

    /**
     * @param array<string, array{0: string, 1: ?bool}> $changes column => [new type, new nullability (null: kept)]
     * @param string $what the change, for messages
     */
    private function rebuild(\PDO $pdo, string $table, array $changes, string $what): void
    {
        $create = self::createStatement($pdo, $table);
        // Identifiers and literals out of the way, so a column named "check" is no constraint.
        $bare = (string) preg_replace('/"(?:[^"]|"")*"|\'(?:[^\']|\'\')*\'|`(?:[^`]|``)*`|\[[^\]]*\]/', '', $create);
        if (preg_match('/\b(REFERENCES|CHECK|UNIQUE|CONSTRAINT|AUTOINCREMENT|GENERATED|WITHOUT\s+ROWID|STRICT)\b|\bAS\s*\(/i', $bare, $m) === 1) {
            throw new \LogicException("Cannot convert {$what} on SQLite: the table is rebuilt for it, and its definition has " . strtoupper($m[1] ?? 'AS (') . ', which the rebuild cannot keep.');
        }
        $foreign = array_diff(array_map('strtolower', self::collationNames($create)), self::KEPT_COLLATIONS);
        if ($foreign !== []) {
            throw new \LogicException("Cannot convert {$what} on SQLite: the table is rebuilt for it, and its definition has COLLATE "
                . implode(', ', array_unique($foreign)) . ', which the rebuild cannot keep (it keeps ' . SqliteDialect::TEXT_COLLATION . ' and BINARY).');
        }
        $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;
        if ($foreignKeys && $pdo->inTransaction()) {
            throw new \LogicException("Cannot convert {$what} on SQLite inside a transaction while foreign keys are on: SQLite cannot switch them off there.");
        }

        $collations = self::declaredCollations($create);
        $own = [];
        foreach ($changes as $name => $change) {
            $own[strtolower((string) $name)] = $change;
        }
        $info = $pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);
        $keys = array_values(array_filter($info, static fn (array $c): bool => (int) $c['pk'] > 0));
        usort($keys, static fn (array $a, array $b): int => (int) $a['pk'] <=> (int) $b['pk']);
        $defs = [];
        foreach ($info as $col) {
            $name = (string) $col['name'];
            $change = $own[strtolower($name)] ?? null;
            if ($change !== null) {
                $def = trim($this->quote($name) . ' ' . $change[0]);
            } else {
                $collation = $collations[strtolower($name)] ?? null;
                $def = trim($this->quote($name) . ' ' . (string) $col['type']
                    . ($collation !== null && strtolower($collation) !== 'binary' ? ' COLLATE ' . $collation : ''));
            }
            if (count($keys) === 1 && (int) $col['pk'] === 1) {
                $def .= ' PRIMARY KEY';
            }
            if ($change !== null ? ($change[1] ?? (int) $col['notnull'] === 0) === false : (int) $col['notnull'] === 1) {
                $def .= ' NOT NULL';
            }
            if ($col['dflt_value'] !== null) {
                $def .= ' DEFAULT ' . $col['dflt_value'];
            }
            $defs[] = $def;
        }
        if (count($keys) > 1) {
            $defs[] = 'PRIMARY KEY (' . implode(', ', array_map(fn (array $c): string => $this->quote((string) $c['name']), $keys)) . ')';
        }
        $kept = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type IN ('index', 'trigger') AND tbl_name = ? AND sql IS NOT NULL ORDER BY type");
        $kept->execute([$table]);
        $remake = $kept->fetchAll(\PDO::FETCH_COLUMN);
        $kept = null;
        $new = $table . '__preflow_converting';
        $names = implode(', ', array_map(fn (array $c): string => $this->quote((string) $c['name']), $info));

        if ($foreignKeys) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }
        $legacy = (int) $pdo->query('PRAGMA legacy_alter_table')->fetchColumn();
        $pdo->exec('SAVEPOINT preflow_convert_column');
        try {
            $pdo->exec('CREATE TABLE ' . $this->quote($new) . ' (' . implode(', ', $defs) . ')');
            $pdo->exec('INSERT INTO ' . $this->quote($new) . " ({$names}) SELECT {$names} FROM " . $this->quote($table));
            $pdo->exec('DROP TABLE ' . $this->quote($table));
            // Legacy rename: views and triggers elsewhere that name the table are left as they are (they
            // name it again once the rename is done) instead of being checked against the missing table.
            $pdo->exec('PRAGMA legacy_alter_table = ON');
            $pdo->exec('ALTER TABLE ' . $this->quote($new) . ' RENAME TO ' . $this->quote($table));
            $pdo->exec('PRAGMA legacy_alter_table = ' . $legacy);
            foreach ($remake as $sql) {
                $pdo->exec((string) $sql);
            }
            if ($foreignKeys && $pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new \LogicException("Cannot convert {$what}: the rebuilt table breaks a foreign key.");
            }
            $pdo->exec('RELEASE preflow_convert_column');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK TO preflow_convert_column');
            $pdo->exec('RELEASE preflow_convert_column');
            $pdo->exec('PRAGMA legacy_alter_table = ' . $legacy);
            throw $e;
        } finally {
            if ($foreignKeys) {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    }

    private static function createStatement(\PDO $pdo, string $table): string
    {
        $find = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $find->execute([$table]);
        $create = (string) $find->fetchColumn();
        $find->closeCursor(); // an open statement would lock the table against a DROP

        return $create;
    }

    /**
     * Every collation an SQL text names, as written (string literals left out).
     *
     * @return list<string>
     */
    private static function collationNames(string $sql): array
    {
        $code = (string) preg_replace('/\'(?:[^\']|\'\')*\'/', "''", $sql);
        preg_match_all('/\bCOLLATE\s+(?:"((?:[^"]|"")*)"|`([^`]*)`|\[([^\]]*)\]|(\w+))/i', $code, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): string => str_replace('""', '"', $m[1] . ($m[2] ?? '') . ($m[3] ?? '') . ($m[4] ?? '')), $matches);
    }

    /**
     * Each column's declared COLLATE in a CREATE TABLE statement: the column definitions are its
     * top-level parts that start with a name (table constraints are left out).
     *
     * @return array<string, string> lower-case column name => collation as written
     */
    private static function declaredCollations(string $create): array
    {
        $open = strpos($create, '(');
        $close = strrpos($create, ')');
        if ($open === false || $close === false || $close < $open) {
            return [];
        }
        $body = substr($create, $open + 1, $close - $open - 1);
        $parts = [];
        $depth = 0;
        $current = '';
        $tokens = preg_split('/("(?:[^"]|"")*"|\'(?:[^\']|\'\')*\'|`(?:[^`]|``)*`|\[[^\]]*\])/', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($tokens as $i => $token) {
            if ($i % 2 === 1) { // a quoted name or a literal
                $current .= $token;
                continue;
            }
            foreach (str_split($token) as $char) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
                $current .= $char;
            }
        }
        $parts[] = $current;

        $collations = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^(?:"((?:[^"]|"")*)"|`((?:[^`]|``)*)`|\[([^\]]*)\]|(\w+))(.*)$/s', $part, $m) !== 1) {
                continue;
            }
            $name = $m[1] !== '' ? str_replace('""', '"', $m[1]) : ($m[2] !== '' ? str_replace('``', '`', $m[2]) : ($m[3] !== '' ? $m[3] : $m[4]));
            if ($m[4] !== '' && in_array(strtoupper($m[4]), ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN'], true)) {
                continue;
            }
            $names = self::collationNames($m[5]);
            if ($names !== []) {
                $collations[strtolower($name)] = $names[0];
            }
        }

        return $collations;
    }

    public function createMigrationsTable(): string
    {
        return 'CREATE TABLE IF NOT EXISTS preflow_migrations (
            migration TEXT PRIMARY KEY,
            ran_at TEXT NOT NULL
        )';
    }

    public function canIndex(string $kind): bool
    {
        return true;
    }
}
