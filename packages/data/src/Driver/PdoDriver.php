<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\VersionedStorage;

abstract class PdoDriver implements VersionedStorage
{
    public function __construct(
        protected readonly \PDO $pdo,
        protected readonly Dialect $dialect,
        protected readonly QueryCompiler $compiler,
        protected readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
    ) {}

    /** The connection this driver runs on (the migrator needs it). */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** The SQL dialect of this connection (callers writing their own SQL branch on it). */
    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    /**
     * Values as this connection's execute() binds them (all as strings). The
     * default leaves them alone, so SQLite stores exactly what it did before.
     *
     * @param array<int|string, mixed> $bindings
     * @return array<int|string, mixed>
     */
    protected function prepareBindings(array $bindings): array
    {
        return $bindings;
    }

    protected function executeWithLogging(\PDOStatement $stmt, string $sql, array $bindings = []): void
    {
        $start = hrtime(true);
        $stmt->execute($this->prepareBindings($bindings));
        if ($this->collector !== null) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->collector->logQuery($sql, $bindings, $durationMs);
        }
    }

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        $table = $this->dialect->quoteIdentifier($type);
        $col = $this->dialect->quoteIdentifier($idField);
        $sql = "SELECT * FROM {$table} WHERE {$col} = ? LIMIT 1";
        $stmt = $this->read($type, $sql, [$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        // Get total count (without limit/offset)
        [$countSql, $countBindings] = $this->compiler->compileCount($type, $query);
        $countStmt = $this->read($type, $countSql, $countBindings);
        $total = (int) $countStmt->fetchColumn();

        // Get items
        [$sql, $bindings] = $this->compiler->compile($type, $query);
        $stmt = $this->read($type, $sql, $bindings);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return new ResultSet($items, $total);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        // Structured values (e.g. translatable locale maps) are stored as JSON text.
        $data = array_map(
            static fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $v,
            $data,
        );

        $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

        if ($isEmpty) {
            unset($data[$idField]);
            $data = array_filter($data, fn ($v) => $v !== null);
            $columns = array_keys($data);
            $sql = $this->dialect->insertSql($type, $columns);
            $this->write($type, $sql, array_values($data));
        } else {
            if (!isset($data[$idField])) {
                $data[$idField] = $id;
            }

            // Filter out null values
            $data = array_filter($data, fn ($v) => $v !== null);

            $columns = array_keys($data);
            $sql = $this->dialect->upsertSql($type, $columns, $idField);
            $this->write($type, $sql, array_values($data));
        }
    }

    /**
     * The version check is the write's own UPDATE: it claims the record by setting the version
     * column WHERE the stored version is $expected, inside a transaction (the row stays locked
     * until it ends), then writes $data as save() does. A transaction the caller already opened
     * is used as it is and left to the caller.
     *
     * A first save ($expected 0) of a record that does not exist yet inserts it instead (no
     * claim first: on MySQL the claim of a missing row takes a gap lock, and two such first
     * saves then deadlock on their INSERTs); a concurrent first save that inserted it first
     * makes the INSERT fail on the primary key, and the claim decides.
     *
     * The stored version is compared as text: only a canonical integer string matches ('5', not
     * '05' or '5.0'), and NULL or '' match 0. An integral version in $data is written canonical.
     *
     * A lost race the server reports as an error (a deadlock, 40001; MariaDB's snapshot
     * isolation, 1020) gives false when this call opened the transaction (the server rolled it
     * back, nothing was written). In a caller's transaction it is rethrown: the caller's
     * transaction is gone. So is SQLite's refusal to write from a caller's transaction that read
     * before another connection committed (WAL, SQLITE_BUSY_SNAPSHOT, "database is locked").
     */
    public function saveIfVersion(string $type, string|int $id, array $data, string $versionField, int $expected, string $idField = 'uuid'): bool
    {
        if (($data[$versionField] ?? null) === null) {
            throw new \InvalidArgumentException("saveIfVersion() needs the version to write in [{$versionField}].");
        }
        if ($id === '' || $id === 0 || $id === '0') {
            throw new \InvalidArgumentException('saveIfVersion() needs the record id.');
        }
        $data[$idField] ??= $id;
        $data[$versionField] = self::canonicalVersion($data[$versionField]);
        $next = (string) $data[$versionField];

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $written = $expected === 0 && !$this->exists($type, $id, $idField) && $this->insertNew($type, $data);
            if (!$written && $this->claim($type, $id, $next, $versionField, $expected, $idField)) {
                $this->save($type, $id, $data, $idField);
                $written = true;
            }
            if ($own) {
                $written ? $this->pdo->commit() : $this->pdo->rollBack();
            }

            return $written;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($own && $e instanceof \PDOException && self::isLostRace($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** An integral number or numeric string as its canonical integer string ('05', 5.0: '5'); anything else as it is. */
    private static function canonicalVersion(mixed $version): mixed
    {
        if (is_int($version)) {
            return (string) $version;
        }
        if ((is_string($version) || is_float($version)) && is_numeric($version) && (float) $version == (int) $version) {
            return (string) (int) $version;
        }

        return $version;
    }

    /** A deadlock or serialization failure (40001), or MariaDB's "record has changed since last read" (1020). */
    private static function isLostRace(\PDOException $e): bool
    {
        $state = $e->errorInfo[0] ?? (is_string($e->getCode()) ? $e->getCode() : null);

        return $state === '40001' || ($e->errorInfo[1] ?? null) === 1020;
    }

    /** Set the version column where the stored version is $expected; whether the record matched. */
    private function claim(string $type, string|int $id, string $next, string $versionField, int $expected, string $idField): bool
    {
        $table = $this->dialect->quoteIdentifier($type);
        $version = $this->dialect->quoteIdentifier($versionField);
        $col = $this->dialect->quoteIdentifier($idField);
        // Compared as text: the column is text in Folio's tables, and a number column takes it too.
        $matches = $expected === 0 ? "({$version} IS NULL OR {$version} = '' OR {$version} = ?)" : "{$version} = ?";

        $update = "UPDATE {$table} SET {$version} = ? WHERE {$col} = ? AND {$matches}";
        if ($this->write($type, $update, [$next, $id, (string) $expected])->rowCount() > 0) {
            return true;
        }
        if ($next !== (string) $expected) {
            return false;
        }

        // MySQL without FOUND_ROWS counts changed rows only: a write keeping the version matched
        // but changed nothing. A locking read, as the UPDATE was: in a caller's transaction a
        // plain read would see its snapshot, possibly older than a save committed since.
        $select = "SELECT 1 FROM {$table} WHERE {$col} = ? AND {$matches} LIMIT 1" . $this->dialect->lockingRead();

        return $this->read($type, $select, [$id, (string) $expected])->fetchColumn() !== false;
    }

    /**
     * A plain INSERT (no upsert) for a record expected not to exist yet; a concurrent insert of
     * the same id makes it fail on the primary key, which is a lost race and not an error.
     *
     * @param array<string, mixed> $data
     */
    private function insertNew(string $type, array $data): bool
    {
        $data = array_map(
            static fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $v,
            $data,
        );
        $data = array_filter($data, fn ($v) => $v !== null);
        try {
            $this->write($type, $this->dialect->insertSql($type, array_keys($data)), array_values($data));

            return true;
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Runs a write; a record key the table has no column for (a model field
     * added without migrating) names the fix instead of the raw PDO error.
     *
     * @param list<mixed> $bindings
     */
    private function write(string $table, string $sql, array $bindings): \PDOStatement
    {
        $stmt = null;
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->executeWithLogging($stmt, $sql, $bindings);

            return $stmt;
        } catch (\PDOException $e) {
            // The exception's trace holds the statement; reset it, or SQLite keeps its read open.
            $stmt?->closeCursor();
            if (preg_match('/(?:no such column:|has no column named|Unknown column)\s*[\'"`]?([A-Za-z0-9_]+)/', $e->getMessage(), $m) === 1) {
                throw new \RuntimeException("Table {$table} has no column {$m[1]}; run `preflow migrate`.", 0, $e);
            }
            throw $e;
        }
    }

    /**
     * Runs a read; a missing table (a type never migrated) names the fix
     * instead of the raw PDO error.
     *
     * @param list<mixed> $bindings
     */
    private function read(string $table, string $sql, array $bindings): \PDOStatement
    {
        $stmt = null;
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->executeWithLogging($stmt, $sql, $bindings);

            return $stmt;
        } catch (\PDOException $e) {
            $stmt?->closeCursor();
            if (preg_match('/no such table|base table or view not found|relation .* does not exist/i', $e->getMessage()) === 1) {
                throw new \RuntimeException("Table {$table} does not exist; run `preflow migrate`.", 0, $e);
            }
            throw $e;
        }
    }

    public function lastInsertId(): string|int
    {
        $id = $this->pdo->lastInsertId();

        return is_numeric($id) ? (int) $id : $id;
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $table = $this->dialect->quoteIdentifier($type);
        $col = $this->dialect->quoteIdentifier($idField);
        $sql = "DELETE FROM {$table} WHERE {$col} = ?";
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        $table = $this->dialect->quoteIdentifier($type);
        $col = $this->dialect->quoteIdentifier($idField);
        $sql = "SELECT 1 FROM {$table} WHERE {$col} = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, $bindings);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Runs a write statement (UPDATE, DELETE, an upsert) and returns the affected rows.
     * Ints, bools and nulls are bound with their type, so SQL arithmetic and
     * comparisons on bound values work as numbers (SQLite orders any text above
     * any number).
     *
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            });
        }
        $start = hrtime(true);
        $stmt->execute();
        $this->collector?->logQuery($sql, $bindings, (hrtime(true) - $start) / 1_000_000);

        return $stmt->rowCount();
    }
}
