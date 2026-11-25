<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\AggregateQuery;
use Preflow\Data\AggregatingStorage;
use Preflow\Data\ExclusiveWrites;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\UniqueViolation;
use Preflow\Data\VersionedStorage;

abstract class PdoDriver implements VersionedStorage, ExclusiveWrites, AggregatingStorage, \Preflow\Data\ConditionalMeasures, \Preflow\Data\BulkDeletes, \Preflow\Data\Savepoints, \Preflow\Data\ConditionalUpdates, \Preflow\Data\DescribesColumns, \Preflow\Data\PdoConnection, \Preflow\Data\Transactions, \Preflow\Data\AtomicCounters, \Preflow\Data\UniqueIndexes, \Preflow\Data\InsertsNew, \Preflow\Data\SelectsColumns, \Preflow\Data\SelectsSubqueries, \Preflow\Data\StatesIdKinds, \Preflow\Data\RemembersColumns, \Preflow\Data\ConditionalWrites
{
    private ?\PDO $connection = null;

    /** @var array<string, array<string, ?\Preflow\Data\IdKind>> the id kind read per table and id column */
    private array $idKinds = [];
    private ?\Closure $connector = null;

    /** The connection, opened on first use when the driver was given a closure instead of a PDO. */
    protected \PDO $pdo {
        get {
            if ($this->connection === null) {
                // Kept until connect and configure both succeed, so a failure throws the same way again.
                $pdo = ($this->connector)();
                $this->configure($pdo);
                $this->connection = $pdo;
                $this->connector = null;
            }

            return $this->connection;
        }
    }

    /**
     * @param \PDO|\Closure(): \PDO $pdo A connection, or a closure that opens it on the first query.
     */
    public function __construct(
        \PDO|\Closure $pdo,
        protected readonly Dialect $dialect,
        protected readonly QueryCompiler $compiler,
        protected readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
    ) {
        if ($pdo instanceof \PDO) {
            $this->connection = $pdo;
            $this->configure($pdo);
        } else {
            $this->connector = $pdo;
        }
    }

    /** Session setup that runs once, when the connection is made (or handed in). */
    protected function configure(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** Whether the connection has been opened (a lazy driver stays closed until its first query). */
    public function connected(): bool
    {
        return $this->connection !== null;
    }

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
        [$sql, $bindings] = $this->compiler->compile($type, $query);
        $items = $this->read($type, $sql, $bindings)->fetchAll(\PDO::FETCH_ASSOC);

        // Every match (no limit), or a first page that is not full: the total needs no COUNT query.
        $limit = $query->getLimit();
        if (($query->getOffset() ?? 0) === 0 && ($limit === null || count($items) < $limit)) {
            $total = count($items);
        } else {
            [$countSql, $countBindings] = $this->compiler->compileCount($type, $query);
            $total = (int) $this->read($type, $countSql, $countBindings)->fetchColumn();
        }

        return new ResultSet($items, $total);
    }

    public function pluck(string $type, string $field, Query $query): array
    {
        [$sql, $bindings] = $this->compiler->compilePluck($type, $field, $query);

        return $this->read($type, $sql, $bindings)->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function aggregate(AggregateQuery $query): array
    {
        [$sql, $bindings] = $this->compiler->compileAggregate($query);

        return $this->read($query->table(), $sql, $bindings)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        // Structured values (e.g. translatable locale maps) are stored as JSON text, with its
        // umlauts and slashes as they are, so CONTAINS finds "Über" or "a/b" in a list as the JSON
        // driver does (rows written before 0.77 keep "a\/b").
        $data = array_map(
            static fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $v,
            $data,
        );

        $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

        if ($isEmpty) {
            unset($data[$idField]);
            $this->write($type, $this->dialect->insertSql($type, array_keys($data)), array_values($data), $idField);

            return;
        }

        $data[$idField] ??= $id;

        // Only the row with this id is written. A null clears its column; a column not passed is
        // left alone; a duplicate on a unique index other than the primary key fails with a
        // UniqueViolation and touches no row.
        $upsert = $this->dialect->upsertSql($type, array_keys($data), $idField);
        if ($upsert !== null) {
            $this->write($type, $upsert, array_values($data), $idField);

            return;
        }

        // A plain read first, then INSERT or UPDATE ... WHERE id = ?: an UPDATE of a missing id
        // takes a gap lock in a transaction, and two first saves into one gap then deadlock.
        $set = array_diff_key($data, [$idField => true]);
        if ($this->exists($type, $id, $idField) && ($set === [] || $this->updated($type, $id, $set, $idField))) {
            return;
        }
        try {
            $this->write($type, $this->dialect->insertSql($type, array_keys($data)), array_values($data), $idField);
        } catch (\PDOException $e) {
            // A concurrent first save inserted the id between our check and insert: update it.
            if (!$this->dialect->isPrimaryKeyViolation($e, $type, $idField)) {
                throw $e;
            }
            if ($set !== []) {
                $this->updated($type, $id, $set, $idField);
            }
        }
    }

    /**
     * UPDATE of the record's columns; whether the record matched. MySQL without FOUND_ROWS counts
     * changed rows only, so a write that changed nothing is told apart by looking for the row.
     *
     * @param array<string, mixed> $set
     */
    private function updated(string $type, string|int $id, array $set, string $idField): bool
    {
        $sql = $this->dialect->updateSql($type, array_keys($set), $idField);

        return $this->write($type, $sql, [...array_values($set), $id], $idField)->rowCount() > 0
            || $this->exists($type, $id, $idField);
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

        $own = !$this->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $written = $expected === 0 && !$this->exists($type, $id, $idField) && $this->insertNew($type, $data, $idField);
            if (!$written && $this->claim($type, $id, $next, $versionField, $expected, $idField)) {
                $this->save($type, $id, $data, $idField);
                $written = true;
            }
            if ($own) {
                $written ? $this->pdo->commit() : $this->pdo->rollBack();
            }

            return $written;
        } catch (\Throwable $e) {
            if ($own && $this->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($own && $e instanceof \PDOException && self::isLostRace($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * One UPDATE of the named columns WHERE the id and the version match. MySQL without FOUND_ROWS
     * counts changed rows only, so a match that changed nothing is confirmed by a locking read in the
     * same transaction.
     */
    public function updateIfVersion(string $type, string|int $id, array $fields, string $versionField, ?int $expected, string $idField = 'uuid'): bool
    {
        if ($fields === [] || array_key_exists($idField, $fields)) {
            throw new \InvalidArgumentException('updateIfVersion() needs the fields to write, without the id.');
        }
        if ($id === '' || $id === 0 || $id === '0') {
            throw new \InvalidArgumentException('updateIfVersion() needs the record id.');
        }
        $values = [];
        $set = [];
        foreach ($fields as $name => $value) {
            $set[] = $this->dialect->quoteIdentifier($name) . ' = ?';
            $values[] = match (true) {
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $name === $versionField => self::canonicalVersion($value),
                default => $value,
            };
        }
        $table = $this->dialect->quoteIdentifier($type);
        $version = $this->dialect->quoteIdentifier($versionField);
        $col = $this->dialect->quoteIdentifier($idField);
        $matches = $expected === null ? $this->dialect->wholeNumber($version) . ' IS NULL' : "{$version} = ?";
        $bindings = $expected === null ? [$id] : [$id, (string) $expected];

        return $this->matchedWrite(function () use ($type, $table, $set, $col, $matches, $values, $bindings, $idField): bool {
            $update = 'UPDATE ' . $table . ' SET ' . implode(', ', $set) . " WHERE {$col} = ? AND {$matches}";
            if ($this->write($type, $update, [...$values, ...$bindings], $idField)->rowCount() > 0) {
                return true;
            }
            $select = "SELECT 1 FROM {$table} WHERE {$col} = ? AND {$matches} LIMIT 1" . $this->dialect->lockingRead();

            return $this->read($type, $select, $bindings)->fetchColumn() !== false;
        });
    }

    /**
     * Runs a conditional write that tells whether its row matched, in a transaction of its own
     * unless one is open (a locking read after it then sees the row the write locked). A lost race
     * the server reports as an error (isLostRace()) is false in its own transaction (rolled back,
     * nothing written); in a caller's transaction it is rethrown.
     *
     * @param \Closure(): bool $write
     */
    private function matchedWrite(\Closure $write): bool
    {
        $own = !$this->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $written = $write();
            if ($own) {
                $this->pdo->commit();
            }

            return $written;
        } catch (\Throwable $e) {
            if ($own && $this->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($own && $e instanceof \PDOException && self::isLostRace($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * One `UPDATE … WHERE id = ? AND (condition)` (ConditionCompiler), run through execute(); the
     * affected rows decide. MySQL without FOUND_ROWS (a connection not made with
     * MysqlDriver::connectionOptions()) counts changed rows only, so when no row changed and the
     * write could have left the row as it was (no Add), a locking read in the same transaction
     * asks whether the row holds the condition and already every value written. Under READ
     * COMMITTED a commit landing between the two may make that read say yes for a row another
     * writer brought into exactly that state; the row then is what this write would have made
     * it. A missing column names the fix (`preflow migrate`), as save() does.
     *
     * @since 0.89.0
     */
    public function updateWhen(string $type, string|int $id, \Preflow\Data\Condition $when, array $set, string $idField = 'uuid'): bool
    {
        if ($set === [] || array_key_exists($idField, $set)) {
            throw new \InvalidArgumentException('updateWhen() needs the fields to write, without the id field; give at least one other field.');
        }
        $compiler = new ConditionCompiler($this->dialect);
        [$update, $bindings] = $compiler->update($type, $idField, $id, $when, $set);

        $unchanged = ConditionCompiler::unchangedBy($set);

        return $this->matchedWrite(function () use ($compiler, $update, $bindings, $type, $idField, $id, $when, $unchanged): bool {
            if ($this->conditionalWrite($type, $update, $bindings, $idField) > 0) {
                return true;
            }
            if ($unchanged === null) {
                return false; // an Add changes the row: no affected row means no match
            }
            [$select, $matchBindings] = $compiler->matches($type, $idField, $id, \Preflow\Data\Condition::all($when, $unchanged));

            return $this->read($type, $select, $matchBindings)->fetchColumn() !== false;
        });
    }

    /**
     * execute() of a conditional UPDATE or DELETE (a subclass may watch execute()), its failures
     * read as write() reads them: a missing column names `preflow migrate`, a duplicate in a unique
     * index is a UniqueViolation.
     *
     * @param list<mixed> $bindings
     */
    private function conditionalWrite(string $table, string $sql, array $bindings, string $idField): int
    {
        try {
            return $this->execute($sql, $bindings);
        } catch (\PDOException $e) {
            throw $this->writeFailure($e, $table, $idField);
        }
    }

    /**
     * One `DELETE … WHERE id = ? AND (condition)` (ConditionCompiler), run through execute(); the
     * deleted rows decide.
     *
     * @since 0.89.0
     */
    public function deleteWhen(string $type, string|int $id, \Preflow\Data\Condition $when, string $idField = 'uuid'): bool
    {
        [$delete, $bindings] = (new ConditionCompiler($this->dialect))->delete($type, $idField, $id, $when);

        return $this->conditionalWrite($type, $delete, $bindings, $idField) > 0;
    }

    /**
     * The kind of id the table's key column holds, as the connection's schema grammar classifies
     * its type (ClassifiesIdColumns: SQLite by its affinity rules, MySQL by how it compares a text
     * value with the column); null without such a grammar, for a type it does not classify, or for
     * a column or table that is not there, so the type decides (DataManager::idKind()). The table
     * sync makes a type's key text, so a type whose table it made has a string id whatever its
     * declared id field says. Read from the table (the grammar's columnTypes()) and remembered
     * until forgetColumns(); a missing table or column is read again on the next call (a migrate
     * may add it).
     *
     * @since 0.82.0
     */
    public function idKindOf(\Preflow\Data\TypeDefinition $def): ?\Preflow\Data\IdKind
    {
        if (isset($this->idKinds[$def->table]) && array_key_exists($def->idField, $this->idKinds[$def->table])) {
            return $this->idKinds[$def->table][$def->idField];
        }
        try {
            $grammar = \Preflow\Data\Migration\Grammar\Grammars::named((string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        } catch (\LogicException) {
            return null; // a connection no grammar is registered for classifies nothing
        }
        if (!$grammar instanceof \Preflow\Data\Migration\Grammar\ClassifiesIdColumns) {
            return null;
        }
        $type = $grammar->columnTypes($this->pdo, $def->table)[$def->idField] ?? null;
        if ($type === null) {
            return null; // not remembered: a migrate may make the table
        }

        return $this->idKinds[$def->table][$def->idField] = $grammar->idKindOfColumn($type);
    }

    /**
     * Drops the id kinds read of $table (null: of every table); the table sync calls it after it
     * changes a table. A subclass that overrides it calls parent::forgetColumns($table).
     *
     * @since 0.82.0
     */
    public function forgetColumns(?string $table = null): void
    {
        if ($table === null) {
            $this->idKinds = [];
        } else {
            unset($this->idKinds[$table]);
        }
    }

    public function hasColumn(string $type, string $column): bool
    {
        return in_array($column, (new \Preflow\Data\Migration\Schema($this->pdo))->columns($type), true);
    }

    public function hasTable(string $type): bool
    {
        return (new \Preflow\Data\Migration\Schema($this->pdo))->hasTable($type);
    }

    /**
     * Whether a transaction is open on the connection: one opened through PDO, or one the
     * driver opened itself (SqliteDriver::exclusive()).
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * $fn in a transaction on the connection; inside an open one, joined (its owner commits or
     * rolls back). A failed commit is rolled back where the connection still holds the
     * transaction, and rethrown. @since 0.78
     */
    public function transaction(\Closure $fn): mixed
    {
        if ($this->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }
        try {
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }

        return $result;
    }

    /** Rolls back the transaction the connection still holds; a failure to do so is not the error that matters. */
    private function rollBackQuietly(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (\PDOException) {
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
        // Compared as text (the column is text in Folio's tables); "no version yet" is NULL or '',
        // which the dialect checks without comparing a number column with '' (PostgreSQL refuses that).
        $matches = $expected === 0 ? '(' . $this->dialect->wholeNumber($version) . " IS NULL OR {$version} = ?)" : "{$version} = ?";

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
     * A plain INSERT (never an upsert, never MySQL's INSERT IGNORE, which also swallows other
     * errors): a second writer of the same id fails on the primary key, which is a lost race and
     * not an error. A duplicate on another unique index is still a UniqueViolation. A dialect with
     * InsertIfNew (since 0.80.0) gives its own statement instead, which skips an existing id.
     *
     * @param array<string, mixed> $data
     * @since 0.79.0
     */
    public function insertNew(string $type, array $data, string $idField = 'uuid'): bool
    {
        $id = $data[$idField] ?? null;
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            throw new \InvalidArgumentException("insertNew() needs the id in [{$idField}].");
        }
        $data = array_map(
            static fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $v,
            $data,
        );
        // A dialect whose failed INSERT aborts the transaction writes a statement that skips an
        // existing id instead of failing (InsertIfNew, since 0.80.0): no row written is false.
        $skipping = $this->dialect instanceof InsertIfNew ? $this->dialect->insertNewSql($type, array_keys($data), $idField) : null;
        if ($skipping !== null) {
            return $this->write($type, $skipping, array_values($data), $idField)->rowCount() > 0;
        }
        try {
            $this->write($type, $this->dialect->insertSql($type, array_keys($data)), array_values($data), $idField);

            return true;
        } catch (\PDOException $e) {
            if ($this->dialect->isPrimaryKeyViolation($e, $type, $idField)) {
                return false;
            }
            // A database reports the first index that fails, and SQLite checks a unique index made
            // later before the primary key: the same record stored twice then fails on that index.
            // A record with the id is a duplicate id whichever index said so (a locking read, as
            // claim() does, so a MySQL snapshot does not hide a row committed since).
            if ($e instanceof UniqueViolation && $this->idStored($type, $id, $idField)) {
                return false;
            }
            throw $e;
        }
    }

    private function idStored(string $type, string|int $id, string $idField): bool
    {
        $table = $this->dialect->quoteIdentifier($type);
        $col = $this->dialect->quoteIdentifier($idField);
        $sql = "SELECT 1 FROM {$table} WHERE {$col} = ? LIMIT 1" . $this->dialect->lockingRead();

        return $this->read($type, $sql, [$id])->fetchColumn() !== false;
    }

    /**
     * Runs a write; a record key the table has no column for (a model field
     * added without migrating) names the fix instead of the raw PDO error. With the
     * table's $idField, a duplicate on another unique index is a UniqueViolation.
     *
     * @param list<mixed> $bindings
     */
    private function write(string $table, string $sql, array $bindings, ?string $idField = null): \PDOStatement
    {
        $stmt = null;
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->executeWithLogging($stmt, $sql, $bindings);

            return $stmt;
        } catch (\PDOException $e) {
            // The exception's trace holds the statement; reset it, or SQLite keeps its read open.
            $stmt?->closeCursor();
            throw $this->writeFailure($e, $table, $idField);
        }
    }

    /** A failed write as the caller should see it: a missing column names the fix, a duplicate is a UniqueViolation. */
    private function writeFailure(\PDOException $e, string $table, ?string $idField): \Throwable
    {
        $column = $this->dialect->missingColumn($e);
        if ($column !== null) {
            return new \RuntimeException("Table {$table} has no column {$column}; run `preflow migrate`.", 0, $e);
        }

        return ($idField === null ? null : $this->dialect->uniqueViolation($e, $table, $idField)) ?? $e;
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

    /** In chunks of DELETE_CHUNK ids, one statement each. @since 0.77 */
    public function deleteMany(string $type, array $ids, string $idField = 'uuid'): void
    {
        $table = $this->dialect->quoteIdentifier($type);
        $col = $this->dialect->quoteIdentifier($idField);
        foreach (array_chunk(array_values($ids), self::DELETE_CHUNK) as $chunk) {
            $sql = "DELETE FROM {$table} WHERE {$col} IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ')';
            $stmt = $this->pdo->prepare($sql);
            $this->executeWithLogging($stmt, $sql, $chunk);
        }
    }

    /** How many ids deleteMany() names in one statement. */
    public const int DELETE_CHUNK = 500;

    private static int $savepoints = 0;

    /** SAVEPOINT / RELEASE / ROLLBACK TO: the same on SQLite, MySQL and PostgreSQL. @since 0.77 */
    public function savepoint(\Closure $fn): mixed
    {
        if (!$this->inTransaction()) {
            return $fn();
        }
        $name = 'preflow_sp_' . ++self::$savepoints;
        $this->pdo->exec("SAVEPOINT {$name}");
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
                $this->pdo->exec("RELEASE SAVEPOINT {$name}");
            } catch (\PDOException) {
                // The transaction itself is gone; $fn's failure is what matters.
            }
            throw $e;
        }
        $this->pdo->exec("RELEASE SAVEPOINT {$name}");

        return $result;
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

    /**
     * One insert-or-update statement of the dialect (AtomicCounters, @since 0.78), then a read of
     * the counter. The expressions read the stored row through table-qualified columns (PostgreSQL
     * finds a bare name ambiguous with EXCLUDED's), and the reset field is assigned last: MySQL
     * reads a column assigned earlier in the statement as its new value. A dialect without such a
     * statement counts with a read and a write under exclusive().
     */
    public function increment(string $table, string|int $id, string $field, int $by, array $insert, ?\Preflow\Data\CounterReset $reset = null, string $idField = 'uuid'): int
    {
        \Preflow\Data\Counters::assertArguments($field, $insert, $reset, $idField);
        $q = $this->dialect->quoteIdentifier(...);
        $column = static fn (string $name): string => $q($table) . '.' . $q($name);
        $counter = $column($field);

        $set = [];
        $bindings = [$id, ...array_values($insert), $by];
        if ($reset === null) {
            $set[$field] = "COALESCE({$counter}, 0) + ?";
            $bindings[] = $by;
        } else {
            $restart = $column($reset->field) . ' < ?';
            $set[$field] = "CASE WHEN {$restart} THEN ? ELSE COALESCE({$counter}, 0) + ? END";
            array_push($bindings, $reset->below, $by, $by);
            $assigned = $reset->set;
            if (array_key_exists($reset->field, $assigned)) {
                // Last: the CASEs before it must read the stored value.
                $own = $assigned[$reset->field];
                unset($assigned[$reset->field]);
                $assigned[$reset->field] = $own;
            }
            foreach ($assigned as $name => $value) {
                $set[$name] = "CASE WHEN {$restart} THEN ? ELSE " . $column($name) . ' END';
                array_push($bindings, $reset->below, $value);
            }
        }

        $sql = $this->dialect->insertOrUpdateSql($table, [$idField, ...array_keys($insert), $field], $idField, $set);
        if ($sql === null) {
            return $this->exclusive($table, fn (): int => \Preflow\Data\Counters::step($this, $table, $id, $field, $by, $insert, $reset, $idField));
        }
        $this->execute($sql, $bindings);

        $read = 'SELECT ' . $q($field) . ' FROM ' . $q($table) . ' WHERE ' . $q($idField) . ' = ? LIMIT 1';

        return (int) $this->read($table, $read, [$id])->fetchColumn();
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
