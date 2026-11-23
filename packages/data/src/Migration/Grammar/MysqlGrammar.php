<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\FieldKinds;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/**
 * MariaDB 10.6+ / MySQL 8. InnoDB utf8mb4 limits index keys to 3072 bytes (767 on old row formats),
 * so primary keys and indexed columns stay VARCHAR; TEXT/LONGTEXT cannot be indexed without a prefix.
 * Since 0.78 the columns of text fields (and a migration's string() and text()) take the text
 * rule's collation the server has (TextCollations, MysqlDialect::MATCH_COLLATIONS, probed once
 * per connection by on()), which is also the table default; every other column of a record table
 * (ids, keys, machine values, dates, other free text) and a migration's uuid(), primary key and
 * exact() column, json() and timestamps are utf8mb4_bin, so they compare exactly ('A', 'a' and 'ä' are three rows, not
 * one upsert target). On a server without the collation, or unbound, text columns are exact too
 * and a migration's string() keeps the table default utf8mb4_unicode_ci.
 */
final class MysqlGrammar implements SchemaGrammar, FullTextIndexes, ConvertsCollations, ClassifiesIdColumns
{
    use RecordIndexNames;

    private const EXACT = ' COLLATE utf8mb4_bin';

    /** MySQL's error numbers for a collation the server does not have (1273) or not for utf8mb4 (1253). */
    private const array UNKNOWN_COLLATION = [1273, 1253];

    /** The collation text columns name (on()), null for none. */
    private ?string $textCollation = null;

    /** The collation LIKE compares in (on()), null for none. */
    private ?string $searchCollation = null;

    /** @var \WeakMap<\PDO, array<string, ?string>>|null each connection's probed collation, by the candidates probed */
    private static ?\WeakMap $probed = null;

    /**
     * @param list<string>|null $collations the collations on() probes, in preference order (null:
     *     MysqlDialect::MATCH_COLLATIONS); [] binds to none, as on a server without them (tests)
     * @param list<string>|null $searchCollations the search collations on() probes, in
     *     preference order (null: MysqlDialect::SEARCH_COLLATIONS); [] binds to none (@since 0.78.1)
     */
    public function __construct(private readonly ?array $collations = null, private readonly ?array $searchCollations = null)
    {
    }

    /**
     * Bound to the first of MysqlDialect::MATCH_COLLATIONS and the first of
     * MysqlDialect::SEARCH_COLLATIONS the server accepts: each is probed (SELECT _utf8mb4'a'
     * COLLATE name) until one does not fail with "unknown collation", once per connection. The
     * server's collation list is not read: information_schema.COLLATIONS does not list MariaDB's
     * utf8mb4_uca1400_* names (it lists uca1400_*). Runs on the connection itself, so
     * MysqlDriver's SET NAMES utf8mb4 is in force.
     */
    public function on(\PDO $pdo): static
    {
        $bound = clone $this;
        $bound->textCollation = self::probed($pdo, $this->collations ?? MysqlDialect::MATCH_COLLATIONS);
        $bound->searchCollation = self::probed($pdo, $this->searchCollations ?? MysqlDialect::SEARCH_COLLATIONS);

        return $bound;
    }

    public function textCollation(): ?string
    {
        return $this->textCollation;
    }

    /**
     * The collation LIKE, NOT LIKE, CONTAINS and search() compare in (ignore case and accents):
     * the first of MysqlDialect::SEARCH_COLLATIONS the server has (on()), null for none or
     * unbound. Columns never take it.
     *
     * @since 0.78.1
     */
    public function searchCollation(): ?string
    {
        return $this->searchCollation;
    }

    /**
     * The first of the candidates the connection accepts, probed once per connection and list.
     *
     * @param list<string> $candidates
     */
    private static function probed(\PDO $pdo, array $candidates): ?string
    {
        $key = implode(',', $candidates);
        self::$probed ??= new \WeakMap();
        $known = self::$probed[$pdo] ?? [];
        if (!array_key_exists($key, $known)) {
            $known[$key] = self::probe($pdo, $candidates);
            self::$probed[$pdo] = $known;
        }

        return $known[$key];
    }

    /** @param list<string> $candidates */
    private static function probe(\PDO $pdo, array $candidates): ?string
    {
        foreach ($candidates as $collation) {
            try {
                $statement = $pdo->query("SELECT _utf8mb4'a' COLLATE {$collation}");
            } catch (\PDOException $e) {
                if (in_array((int) ($e->errorInfo[1] ?? 0), self::UNKNOWN_COLLATION, true)) {
                    continue;
                }
                throw $e;
            }
            if ($statement === false) {
                if (in_array((int) ($pdo->errorInfo()[1] ?? 0), self::UNKNOWN_COLLATION, true)) {
                    continue;
                }
                throw new \RuntimeException('Probing the collation ' . $collation . ' failed: ' . ($pdo->errorInfo()[2] ?? 'unknown error'));
            }
            $statement->closeCursor();

            return $collation;
        }

        return null;
    }

    /** The table default: the rule's collation where the server has it, else the one tables had before 0.78. */
    private function tableOptions(): string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . ($this->textCollation ?? 'utf8mb4_unicode_ci');
    }

    /**
     * MySQL caps table, column and index names at 64 characters; idx_{table}_{column} and
     * Folio's {table}__published / __revisions can cross it, so fail naming the identifier.
     */
    public function quote(string $identifier): string
    {
        $length = mb_strlen($identifier);
        if ($length > 64) {
            throw new \LogicException("Identifier \"{$identifier}\" is {$length} characters; MySQL allows at most 64.");
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function columnType(string $kind, ?int $length = null): string
    {
        return match ($kind) {
            'uuid' => 'VARCHAR(64)' . self::EXACT,
            'string' => 'VARCHAR(' . ($length ?? 255) . ')',
            'text' => 'TEXT',
            'json' => 'LONGTEXT' . self::EXACT,
            'integer' => 'BIGINT',
            'boolean' => 'TINYINT(1)',
            'timestamp' => 'VARCHAR(32)' . self::EXACT,
            default => throw new \InvalidArgumentException("Unknown column kind \"{$kind}\"."),
        };
    }

    public function createTable(string $table, array $columns): string
    {
        $parts = [];

        foreach ($columns as $col) {
            $type = $this->columnType($col['kind'], $col['length']);
            if ($col['primary'] && $col['kind'] === 'string') {
                $type .= self::EXACT;
            } elseif (in_array($col['kind'], ['string', 'text'], true)) {
                $type = ($col['exact'] ?? false) ? $this->exactType($col['kind'], $col['length']) : $this->textType($col['kind'], $col['length']);
            }
            $def = $this->quote($col['name']) . ' ' . $type;

            if ($col['primary']) {
                $def .= (($col['auto'] ?? false) ? ' AUTO_INCREMENT' : '') . ' PRIMARY KEY';
            } elseif (!$col['nullable']) {
                $def .= ' NOT NULL';
            }

            $parts[] = $def;
        }

        return 'CREATE TABLE ' . $this->quote($table) . ' (' . implode(', ', $parts) . ') ' . $this->tableOptions();
    }

    public function createRecordTable(string $table, string $idField, array $columns): string
    {
        $defs = array_map(fn (ColumnSpec $c): string => $this->quote($c->name) . ' '
            . ($c->text ? $this->textColumnType($c->kind, $c->length) : $this->recordColumnType($c->kind, $c->length)), $columns);

        return 'CREATE TABLE ' . $this->quote($table) . ' (' . $this->quote($idField) . ' VARCHAR(191)' . self::EXACT . ' PRIMARY KEY, '
            . implode(', ', $defs) . ') ' . $this->tableOptions();
    }

    /**
     * A column that is not a text field's compares exactly (utf8mb4_bin, also a free-text one: it
     * holds JSON, lists, machine values). Short columns are unique or indexed. Dates stay ISO text.
     * Every text type names its collation: an ALTER TABLE … MODIFY without one would take the
     * table default.
     */
    public function recordColumnType(ColumnKind $kind, ?int $length = null): string
    {
        return match ($kind) {
            ColumnKind::Text => 'LONGTEXT' . self::EXACT,
            ColumnKind::Short => 'VARCHAR(' . ($length ?? FieldKinds::DEFAULT_LENGTH) . ')' . self::EXACT,
            ColumnKind::Number => 'DOUBLE',
            ColumnKind::Boolean => 'TINYINT(1)',
            ColumnKind::Date => 'VARCHAR(32)' . self::EXACT,
        };
    }

    public function textColumnType(ColumnKind $kind, ?int $length = null): string
    {
        if ($this->textCollation === null) {
            return $this->recordColumnType($kind, $length);
        }

        return match ($kind) {
            ColumnKind::Text => 'LONGTEXT COLLATE ' . $this->textCollation,
            ColumnKind::Short => 'VARCHAR(' . ($length ?? FieldKinds::DEFAULT_LENGTH) . ') COLLATE ' . $this->textCollation,
            default => $this->recordColumnType($kind, $length),
        };
    }

    public function textType(string $kind, ?int $length = null): string
    {
        if ($this->textCollation === null || !in_array($kind, ['string', 'text'], true)) {
            return $this->columnType($kind, $length);
        }

        return $this->columnType($kind, $length) . ' COLLATE ' . $this->textCollation;
    }

    public function exactType(string $kind, ?int $length = null): string
    {
        return $this->columnType($kind, $length) . (in_array($kind, ['string', 'text'], true) ? self::EXACT : '');
    }

    public function columnCollations(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $stmt->execute([$table]);
        $collations = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$name, $collation]) {
            $collations[(string) $name] = $collation === null ? null : (string) $collation;
        }

        return $collations;
    }

    /** One ALTER TABLE … MODIFY a …, MODIFY b …, each column keeping NULL / NOT NULL and its DEFAULT. */
    public function convertCollations(\PDO $pdo, string $table, array $types): void
    {
        if ($types === []) {
            return;
        }
        $clauses = [];
        foreach ($types as $column => $type) {
            $clauses[] = $this->modifyClause($pdo, $table, (string) $column, $type, null);
        }
        $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' ' . implode(', ', $clauses));
    }

    public function tableCollation(\PDO $pdo, string $table): ?string
    {
        $stmt = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $collation = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $collation === false || $collation === null ? null : (string) $collation;
    }

    /** Without CONVERT TO: the columns keep their collations and the rows are not rewritten. */
    public function convertTableCollation(\PDO $pdo, string $table): void
    {
        if ($this->textCollation === null) {
            throw new \LogicException("Cannot give \"{$table}\" the text rule's collation as its default: the server has none of MysqlDialect::MATCH_COLLATIONS.");
        }
        $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DEFAULT CHARACTER SET utf8mb4 COLLATE ' . $this->textCollation);
    }

    /** Any of MysqlDialect::MATCH_COLLATIONS: the server may have more than one name of the rule. */
    public function isTextCollation(?string $collation): bool
    {
        return $collation !== null && in_array(strtolower($collation), MysqlDialect::MATCH_COLLATIONS, true);
    }

    public function exactCollation(): string
    {
        return 'utf8mb4_bin';
    }

    public function isExactCollation(?string $collation): bool
    {
        return $collation !== null && strtolower($collation) === 'utf8mb4_bin';
    }

    /** "type COLLATE name": a MODIFY without a collation would take the table default. */
    public function withCollation(string $type, bool $text): string
    {
        return $type . ' COLLATE ' . ($text ? ($this->textCollation ?? $this->exactCollation()) : $this->exactCollation());
    }

    /**
     * An ENUM or SET (its values are written in the type, which columnTypes() lowers), or a column in
     * another character set than utf8mb4 (latin1, utf8mb3): giving it a utf8mb4 collation would
     * convert its values.
     */
    public function collationRefusal(string $type, ?string $collation): ?string
    {
        if (preg_match('/^\s*(enum|set)\s*\(/i', $type) === 1) {
            return 'an ' . strtoupper((string) strtok(trim($type), '(')) . ' column';
        }
        if ($collation !== null && !str_starts_with(strtolower($collation), 'utf8mb4_')) {
            return "in another character set ({$collation})";
        }

        return null;
    }

    public function collate(string $expression, string $collation): string
    {
        return $expression . ' COLLATE ' . $this->quote($collation);
    }

    public function uniquePrefixes(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SELECT INDEX_NAME, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND SUB_PART IS NOT NULL');
        $stmt->execute([$table]);
        $prefixes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$index, $column, $length]) {
            $prefixes[(string) $index][(string) $column] = (int) $length;
        }

        return $prefixes;
    }

    public function prefix(string $expression, int $length): string
    {
        return "LEFT({$expression}, {$length})";
    }

    /** The primary key's first column, else the table's first column. */
    public function rowKey(\PDO $pdo, string $table): string
    {
        $key = $this->indexColumns($pdo, $table)['PRIMARY'][0] ?? null;

        return $key ?? $this->columns($pdo, $table)[0] ?? throw new \LogicException("The table \"{$table}\" has no columns.");
    }

    public function tables(\PDO $pdo): array
    {
        return array_map('strval', $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** The server supplies the collation: its indexes follow it. */
    public function textCollationVersion(): ?string
    {
        return null;
    }

    public function reindexText(\PDO $pdo): array
    {
        return [];
    }

    /** A TEXT or BLOB column (one made before it was indexed) is indexed by its first 191 characters. */
    public function createRecordIndex(string $name, string $table, array $columns, bool $unique, array $columnTypes = [], array $lengths = []): string
    {
        // A TEXT column is indexed by a prefix: the declared length, at most 191 characters (764 bytes).
        $parts = array_map(fn (string $column): string => $this->quote($column)
            . (preg_match('/(text|blob)$/', strtolower($columnTypes[$column] ?? '')) === 1
                ? '(' . min($lengths[$column] ?? FieldKinds::DEFAULT_LENGTH, FieldKinds::DEFAULT_LENGTH) . ')' : ''), $columns);

        return 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->quote($name) . ' ON ' . $this->quote($table)
            . ' (' . implode(', ', $parts) . ')';
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
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    public function columns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Int for the integer types (serial is bigint unsigned) and for the other numbers (decimal,
     * numeric, float, double, real): MySQL compares a text value with any of them as a number, so
     * for a reserved text id they are not a string. String for char, varchar and the text types.
     * Anything else (binary, blob, json, dates, enum, bit) null.
     *
     * @since 0.82.0
     */
    public function idKindOfColumn(string $type): ?\Preflow\Data\IdKind
    {
        $type = strtolower(trim($type));

        return match (true) {
            preg_match('/^((tiny|small|medium|big)?int(eger)?|serial|dec(imal)?|numeric|fixed|float|double|real)\b/', $type) === 1 => \Preflow\Data\IdKind::Int,
            preg_match('/^((var)?char|(tiny|medium|long)?text)\b/', $type) === 1 => \Preflow\Data\IdKind::String,
            default => null,
        };
    }

    public function columnTypes(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);
        $types = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$name, $type]) {
            $types[(string) $name] = strtolower((string) $type);
        }

        return $types;
    }

    public function indexes(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function uniqueIndexes(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0');
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function indexColumns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $stmt->execute([$table]);
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$name, $column]) {
            $result[(string) $name][] = (string) $column;
        }

        return $result;
    }

    public function createFullTextIndex(string $name, string $table, array $columns): string
    {
        return 'CREATE FULLTEXT INDEX ' . $this->quote($name) . ' ON ' . $this->quote($table)
            . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ')';
    }

    public function fullTextIndexes(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_TYPE = 'FULLTEXT' ORDER BY INDEX_NAME, SEQ_IN_INDEX");
        $stmt->execute([$table]);
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$name, $column]) {
            $result[(string) $name][] = (string) $column;
        }

        return $result;
    }

    public function dropIndex(string $table, string $index): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' DROP INDEX ' . $this->quote($index);
    }

    public function assertCanDropColumn(\PDO $pdo): void
    {
    }

    /** Free text without a type compares exactly (as on SQLite) and names it: the table default may be another collation. */
    public function addColumn(string $table, string $column, ?string $type = null): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->quote($column) . ' ' . ($type ?? 'LONGTEXT' . self::EXACT);
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

    /** ALTER TABLE … MODIFY, with the column's NULL / NOT NULL, DEFAULT, ON UPDATE and COMMENT carried over unless changed. */
    public function convertColumn(\PDO $pdo, string $table, string $column, string $type, ?bool $nullable): void
    {
        $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' ' . $this->modifyClause($pdo, $table, $column, $type, $nullable));
    }

    /** "MODIFY `column` type NULL|NOT NULL [DEFAULT …]", nullability and default as the column has them unless $nullable says. */
    private function modifyClause(\PDO $pdo, string $table, string $column, string $type, ?bool $nullable): string
    {
        $stmt = $pdo->prepare('SELECT IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        [$isNullable, $default, $extra, $comment] = $stmt->fetch(\PDO::FETCH_NUM) ?: throw new \LogicException("Cannot convert \"{$table}.{$column}\": no such column.");
        $stmt->closeCursor();
        $nullable ??= $isNullable === 'YES';
        $sql = 'MODIFY ' . $this->quote($column) . ' ' . $type . ($nullable ? ' NULL' : ' NOT NULL');
        if (str_contains(strtoupper((string) $extra), 'AUTO_INCREMENT')) {
            $sql .= ' AUTO_INCREMENT'; // an increments() key stays numbered
        }
        $default = self::defaultClause($pdo, $default, (string) $extra);
        if ($default !== null) {
            $sql .= ' DEFAULT ' . $default;
        }
        if (preg_match('/\bon update (.+)$/i', (string) $extra, $onUpdate) === 1) {
            $sql .= ' ON UPDATE ' . $onUpdate[1]; // a touched-at column keeps updating itself (@since 0.84.0)
        }
        if ((string) $comment !== '') {
            $sql .= ' COMMENT ' . $pdo->quote((string) $comment); // @since 0.84.0
        }

        return $sql;
    }

    /**
     * information_schema's COLUMN_DEFAULT as SQL. MariaDB (10.2.7+) reports it as SQL already: a quoted
     * literal, an expression, or the word NULL. MySQL 8 reports a literal unquoted and SQL NULL for
     * none; an expression default carries DEFAULT_GENERATED in EXTRA.
     */
    private static function defaultClause(\PDO $pdo, ?string $default, string $extra): ?string
    {
        if ($default === null) {
            return null;
        }
        if (str_contains(strtolower((string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION)), 'mariadb')) {
            return strtoupper($default) === 'NULL' ? null : $default;
        }
        if (str_contains(strtoupper($extra), 'DEFAULT_GENERATED')) {
            return preg_match('/^current_timestamp(\(\d*\))?$/i', $default) === 1 ? $default : '(' . $default . ')';
        }

        return $pdo->quote($default);
    }

    public function createMigrationsTable(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `preflow_migrations` (`migration` VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, `ran_at` VARCHAR(32) COLLATE utf8mb4_bin NOT NULL) '
            . $this->tableOptions();
    }

    public function canIndex(string $kind): bool
    {
        return $kind !== 'text' && $kind !== 'json';
    }
}
