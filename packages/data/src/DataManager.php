<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Attributes\Timestamps;
use Preflow\Data\Observe\DataObserver;
use Preflow\Data\Observe\SqlTables;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Search\LooseFields;
use Preflow\Data\Validation\Unique;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidatorFactory;

final class DataManager
{
    /**
     * @param array<string, StorageDriver|\Closure(): StorageDriver> $drivers Named drivers (e.g.,
     *        'sqlite' => SqliteDriver). A closure is called on the first use of its name and its
     *        driver kept; names given the same closure (aliases) share that driver.
     */
    public function __construct(
        private readonly array $drivers,
        private readonly string $defaultDriver = 'default',
        private ?TypeRegistry $typeRegistry = null,
        private readonly ?ValidatorFactory $validatorFactory = null,
    ) {}

    private ?FieldKinds $fieldKinds = null;

    private ?DataObserver $observer = null;

    private ?VersionFields $versionFields = null;

    /** @var array<string, true> "storage|table|column" found (a missing column is asked again: a migrate may add it) */
    private array $columns = [];

    /**
     * Which types keep a version field that every save bumps (VersionFields); null: none.
     *
     * @since 0.77
     */
    public function useVersionFields(?VersionFields $fields): void
    {
        $this->versionFields = $fields;
    }

    /**
     * The version field records of $def keep (VersionFields), or null.
     *
     * @since 0.77
     */
    public function versionFieldOf(TypeDefinition $def): ?string
    {
        return $this->versionFields?->of($def);
    }

    /**
     * Whether the table of $def has $column: asked of a storage with a schema (DescribesColumns);
     * one without (JSON files) takes any key, so true.
     *
     * @since 0.77
     */
    public function hasColumn(TypeDefinition $def, string $column): bool
    {
        $key = $def->storage . '|' . $def->table . '|' . $column;
        if (isset($this->columns[$key])) {
            return true;
        }
        $driver = $this->resolveDriver($def->storage);
        if ($driver instanceof DescribesColumns && !$driver->hasColumn($def->table, $column)) {
            return false;
        }

        return $this->columns[$key] = true;
    }

    /**
     * A token that moves with every write of $def's records through a driver (ChangeTokens), read
     * before the records it guards; null when the storage has none or cannot tell (reuse nothing).
     *
     * @since 0.77
     */
    public function changeToken(TypeDefinition $def): ?string
    {
        $driver = $this->resolveDriver($def->storage);

        return $driver instanceof ChangeTokens ? $driver->changeToken($def->table) : null;
    }

    /**
     * Whether scoreIn() can rank $def's records: its storage implements RankedSearch, the type
     * declares full-text search ("fullText": true) and the storage can score its loose-search
     * text fields (Search\LooseFields::text(); MySQL: a FULLTEXT index over exactly those, which
     * migrate makes). False otherwise, also for a flagged type before migrate made its index.
     *
     * @since 0.78
     */
    public function canScore(TypeDefinition $def): bool
    {
        if (!$def->fullText) {
            return false;
        }
        $driver = $this->resolveDriver($def->storage);
        if (!$driver instanceof RankedSearch) {
            return false;
        }
        $fields = LooseFields::text($def, $this->fieldKinds());

        return $fields !== [] && $driver->canScore($def->table, $fields);
    }

    /**
     * The score of each of $ids (records of $def) for $term, through the storage's RankedSearch
     * over the type's loose-search text fields: higher is better, 0.0 for one that does not
     * match or does not exist; every given id is a key. Scoring orders records a search found,
     * it never finds any. Ask canScore() first. When scoring fails and the storage, asked again,
     * can no longer score the type (its index was dropped or rebuilt since canScore() said yes),
     * every id scores 0.0, so the records keep storage order; any other failure is rethrown.
     *
     * @since 0.78
     *
     * @param list<string|int> $ids
     * @return array<string|int, float>
     * @throws UnsupportedStorage when the storage does not implement RankedSearch
     * @throws \LogicException when it cannot score this type (canScore() is false)
     */
    public function scoreIn(TypeDefinition $def, string $term, array $ids): array
    {
        $driver = $this->resolveDriver($def->storage);
        if (!$driver instanceof RankedSearch) {
            throw new UnsupportedStorage($def->storage, RankedSearch::class, "scoring {$def->key} records needs it");
        }
        if (!$this->canScore($def)) {
            throw new \LogicException("Storage [{$def->storage}] cannot score {$def->key} records: the type needs \"fullText\": true and, on its storage, a full-text index over its text fields (run `preflow migrate`). Ask canScore() first.");
        }
        if ($ids === []) {
            return [];
        }
        $this->reportRead($def->storage, $def->table);
        $fields = LooseFields::text($def, $this->fieldKinds());
        try {
            return $driver->score($def->table, $term, $fields, $ids, $def->idField);
        } catch (\RuntimeException $e) {
            if ($driver->canScore($def->table, $fields)) {
                throw $e;
            }

            return array_fill_keys($ids, 0.0);
        }
    }

    /**
     * Write $fields, and only those, to the record $id of $def while its $versionField holds
     * $expected (a whole number; null: none yet, never 0 for that) — the compare-and-set
     * ConditionalUpdates gives, the same on every built-in driver. $fields may set $versionField
     * itself; nothing else is bumped. Values are stored as saveType() stores them (storageValues()).
     * A driver without ConditionalUpdates gets a read, compare and write, which is not atomic (an
     * E_USER_NOTICE says so, once per driver class).
     *
     * @since 0.77
     *
     * @param array<string, mixed> $fields not empty; never the id field
     * @return bool false when the record is missing or holds another version (nothing written)
     */
    public function updateFieldsIfVersion(TypeDefinition $def, string|int $id, array $fields, string $versionField, ?int $expected): bool
    {
        $driver = $this->resolveDriver($def->storage);
        $fields = $this->storageValues($def, $fields);
        if ($driver instanceof ConditionalUpdates) {
            $written = $driver->updateIfVersion($def->table, $id, $fields, $versionField, $expected, $def->idField);
        } else {
            self::noticeNotVersioned($driver);
            $stored = $driver->findOne($def->table, $id, $def->idField);
            $value = $stored[$versionField] ?? null;
            $none = $value === null || $value === '';
            $written = $stored !== null && ($expected === null ? $none : !$none && is_scalar($value) && (string) $value === (string) $expected);
            if ($written) {
                $driver->save($def->table, $id, $this->storageValues($def, array_replace($stored, $fields)), $def->idField);
            }
        }
        if ($written) {
            $this->reportWrite($def->storage, $def->table, (string) $id);
        }

        return $written;
    }

    /**
     * Report every read and write through this manager to $observer (the page cache); null
     * stops reporting. Writes made around the manager, straight through driver(), are reported
     * by their callers with reportWrite().
     */
    public function observe(?DataObserver $observer): void
    {
        $this->observer = $observer;
    }

    public function observer(): ?DataObserver
    {
        return $this->observer;
    }

    /**
     * Report a read of $table on $storage: one record, or a listing when $id is null. For readers
     * that go around the manager (QueryBuilder reports its own).
     */
    public function reportRead(string $storage, string $table, string|int|null $id = null): void
    {
        if ($this->observer === null && $this->captures === []) {
            return;
        }
        $this->noteRead(['read', $this->storageKey($storage), $table, $id === null ? null : (string) $id]);
    }

    /** @var list<list<array{0: 'read'|'raw', 1: string, 2: string, 3?: ?string}>> open captureReads() */
    private array $captures = [];

    /**
     * Runs $fn and returns what it returned with the reads it made, for a memo that answers
     * later calls from memory (in this request or a later one in the same process): it hands
     * them to replayReads() on every hit, so each page that uses the memo still depends on what
     * it holds. Reads are captured with or without an observer: a memo filled before one is
     * attached still replays them later.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return array{0: T, 1: list<array<int, mixed>>}
     */
    public function captureReads(\Closure $fn): array
    {
        $depth = count($this->captures);
        $this->captures[] = [];
        try {
            $result = $fn();
        } finally {
            $reads = $this->captures[$depth] ?? [];
            array_splice($this->captures, $depth);
        }

        return [$result, $reads];
    }

    /**
     * Reports again reads captured by captureReads() (a memo hit): to the observer, and to every
     * open captureReads(), with or without an observer, so a memo filled from another memo's hit
     * holds the reads too.
     *
     * @param list<array<int, mixed>> $reads
     */
    public function replayReads(array $reads): void
    {
        if ($this->observer === null && $this->captures === []) {
            return;
        }
        foreach ($reads as $read) {
            $this->noteRead($read);
        }
    }

    /** @param array<int, mixed> $read ['read', storage, table, ?id] or ['raw', storage, sql] */
    private function noteRead(array $read): void
    {
        if ($read[0] === 'raw') {
            $this->observer?->rawRead($read[1], $read[2]);
        } else {
            $this->observer?->read($read[1], $read[2], $read[3] ?? null);
        }
        foreach ($this->captures as $i => $_) {
            $this->captures[$i][] = $read;
        }
    }

    /**
     * Report a write of $table on $storage made around the manager (straight through driver(),
     * an import): one record, or many or unknown ones when $id is null.
     */
    public function reportWrite(string $storage, string $table, string|int|null $id = null): void
    {
        $this->observer?->wrote($this->storageKey($storage), $table, $id === null || $id === '' ? null : (string) $id);
    }

    /** @var array<string, string> storage name => the name it is reported under */
    private array $storageKeys = [];

    /**
     * The name a storage is reported under (to the observer, in page cache tags): one driver configured under several names (the
     * default one, an alias) is one storage, reported as 'default' when it is the default
     * driver, else under its first configured name. An unknown name stays as it is.
     */
    public function reportedStorage(string $name): string
    {
        return $this->storageKey($name);
    }

    private function storageKey(string $name): string
    {
        if (isset($this->storageKeys[$name])) {
            return $this->storageKeys[$name];
        }
        $driver = $this->drivers[$name] ?? null;
        $key = $name;
        if ($driver !== null) {
            if (($this->drivers[$this->defaultDriver] ?? null) === $driver) {
                $key = $this->defaultDriver;
            } else {
                foreach ($this->drivers as $other => $candidate) {
                    if ($candidate === $driver) {
                        $key = (string) $other;
                        break;
                    }
                }
            }
        }

        return $this->storageKeys[$name] = $key;
    }

    public function getTypeRegistry(): ?TypeRegistry
    {
        return $this->typeRegistry;
    }

    /**
     * The registry, $registry when there is none yet (a site without a models directory): a
     * package that defines its types in code (the members core) then still has one. An existing
     * registry is never replaced.
     */
    public function ensureTypeRegistry(TypeRegistry $registry): TypeRegistry
    {
        return $this->typeRegistry ??= $registry;
    }

    public function fieldKinds(): FieldKinds
    {
        return $this->fieldKinds ??= new FieldKinds();
    }

    /**
     * The relations a type declares.
     *
     * @return list<Relation>
     */
    public function relationsOf(string $type): array
    {
        if ($this->typeRegistry === null) {
            return [];
        }
        $relations = [];
        foreach ($this->typeRegistry->get($type)->fields as $field) {
            $relation = Relation::fromField($type, $field, $this->fieldKinds());
            if ($relation !== null) {
                $relations[] = $relation;
            }
        }

        return $relations;
    }

    /**
     * Every relation, across all types, that points at the given type.
     *
     * @return list<Relation>
     */
    public function relationsTo(string $target): array
    {
        if ($this->typeRegistry === null) {
            return [];
        }
        $found = [];
        foreach ($this->typeRegistry->keys() as $type) {
            foreach ($this->relationsOf($type) as $relation) {
                if ($relation->target === $target) {
                    $found[] = $relation;
                }
            }
        }

        return $found;
    }

    /**
     * What deleting these records would do across declared relations, without doing it:
     * records deleted (roots plus "cascade" relations, recursively), records detached
     * ("detach" relations) and records that block the delete ("restrict" relations).
     *
     * @param list<string> $ids
     * @param (\Closure(TypeDefinition): list<TypeDefinition>)|null $copies extra definitions
     *        holding copies of a type's records (e.g. a published shadow table), queried with
     *        the type's relation fields; they count toward restrictions only
     * @param DeletedBy $by Self applies each relation's onSelfDelete rule instead of onDelete
     */
    public function deletePlan(string $type, array $ids, ?\Closure $copies = null, DeletedBy $by = DeletedBy::Staff): DeletePlan
    {
        return (new DeletePlanner($this, $this->requireTypeRegistry()))->plan($type, $ids, $copies, $by);
    }

    /**
     * Carry out a delete plan: detaches (load, remove ids, save without validation),
     * then deletes. A restricted plan is refused with a \RuntimeException before
     * anything changes.
     *
     * SQL writes run in one transaction per PDO connection involved, committed at the
     * end and rolled back on any error (a transaction already open on a connection is
     * left to its owner). JSON-file writes cannot be rolled back: if a later step fails,
     * JSON changes made before it stay.
     */
    public function executeDeletePlan(DeletePlan $plan): void
    {
        (new DeletePlanner($this, $this->requireTypeRegistry()))->execute($plan);
    }

    /**
     * Find a single model by ID.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return T|null
     */
    public function find(string $modelClass, string|int $id): ?Model
    {
        $meta = ModelMetadata::for($modelClass);
        $def = $this->classType($meta);
        $driver = $this->resolveDriver($meta->storage);

        $data = $driver->findOne($meta->table, $id, $meta->idField);
        $this->reportRead($meta->storage, $meta->table, (string) $id);

        return $data === null ? null : $this->hydrate($meta, $def, $data);
    }

    /**
     * Start a query for a typed model. For a class marked #[Entity(type: …)] whose type the
     * registry holds, the query is the type's: translatable fields compare in the query's
     * locale, searchLoose() reaches related records, and it yields objects of the class.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return QueryBuilder<T>
     */
    public function query(string $modelClass): QueryBuilder
    {
        $meta = ModelMetadata::for($modelClass);
        $driver = $this->resolveDriver($meta->storage);
        $def = $this->classType($meta);
        if ($def === null) {
            return new QueryBuilder($driver, $meta, $modelClass, $this);
        }

        return QueryBuilder::forModelType($driver, $meta, $def, $this, fn (array $row): Model => $this->hydrate($meta, $def, $row));
    }

    /** @var \WeakMap<Model, string>|null objects loaded by findAs()/queryAs(), with the table they came from */
    private ?\WeakMap $readOnly = null;

    /**
     * Load a model class from another table of the same shape (a published copy, an archive)
     * as find() loads it from its own. The object is read-only: it is not the record of the
     * class's table, so no write takes it (isReadOnly()).
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return T|null
     */
    public function findAs(string $modelClass, string|int $id, string $table): ?Model
    {
        $meta = ModelMetadata::for($modelClass);
        $def = $this->classType($meta);
        $data = $this->resolveDriver($meta->storage)->findOne($table, $id, $meta->idField);
        $this->reportRead($meta->storage, $table, (string) $id);

        return $data === null ? null : $this->markReadOnly($this->hydrate($meta, $def, $data), $table);
    }

    /**
     * Query a model class in another table of the same shape, as query() does in its own; the
     * objects it yields are read-only (findAs()).
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return QueryBuilder<T>
     */
    public function queryAs(string $modelClass, string $table): QueryBuilder
    {
        $meta = ModelMetadata::for($modelClass);
        $def = $this->classType($meta);

        return QueryBuilder::forModelType(
            $this->resolveDriver($meta->storage),
            $meta,
            $def,
            $this,
            fn (array $row): Model => $this->markReadOnly($this->hydrate($meta, $def, $row), $table),
            $table,
        );
    }

    /** Whether $model was loaded from another table (findAs(), queryAs()) and so cannot be written. */
    public function isReadOnly(Model $model): bool
    {
        return $this->readOnly !== null && isset($this->readOnly[$model]);
    }

    /**
     * @template T of Model
     * @param T $model
     * @return T
     */
    private function markReadOnly(Model $model, string $table): Model
    {
        $this->readOnly ??= new \WeakMap();
        $this->readOnly[$model] = $table;

        return $model;
    }

    private function assertWritable(Model $model): void
    {
        if ($this->isReadOnly($model)) {
            throw new \LogicException(sprintf(
                '%s was loaded read-only (from table %s) and cannot be saved.',
                $model::class,
                $this->readOnly[$model],
            ));
        }
    }

    /**
     * The type a model class is: its #[Entity(type: …)] key's definition in the registry, or
     * null for an ordinary model, which keeps today's typed-model behaviour. A class marked
     * with a key the registry does not know is an error, never the ordinary path: that would
     * write every public property, replace whole JSON records (dropping Folio's columns) and
     * give no Folio id.
     *
     * @throws \LogicException for a type-marked class the registry (or a missing one) does not know
     */
    private function classType(ModelMetadata $meta): ?TypeDefinition
    {
        if ($meta->type === null) {
            return null;
        }
        if ($this->typeRegistry === null || !$this->typeRegistry->has($meta->type)) {
            throw new \LogicException(
                "Model class {$meta->modelClass} is marked as the type \"{$meta->type}\", but "
                . ($this->typeRegistry === null ? 'the data manager has no type registry' : 'the type registry does not know that key')
                . ': put the class under data.model_paths (config/data.php), or register it with the registry.',
            );
        }

        return $this->typeRegistry->get($meta->type);
    }

    /**
     * Execute a raw SQL query and return results.
     * Use for JOINs, subqueries, aggregates, and complex WHERE clauses
     * that QueryBuilder can't express.
     *
     * @param string $sql Raw SQL query
     * @param array<int|string, mixed> $bindings Parameter bindings
     * @param string $storage Driver name (defaults to 'default')
     * @return array<int, array<string, mixed>> Array of associative arrays
     */
    public function raw(string $sql, array $bindings = [], string $storage = 'default'): array
    {
        $driver = $this->sql($storage, 'raw() needs a storage that runs SQL');
        if ($this->observer === null && $this->captures === []) {
            return $driver->rawQuery($sql, $bindings);
        }
        if (!SqlTables::writes($sql)) {
            $rows = $driver->rawQuery($sql, $bindings);
            $this->noteRead(['raw', $this->storageKey($storage), $sql]);

            return $rows;
        }
        try {
            return $driver->rawQuery($sql, $bindings);
        } finally {
            $this->observer?->rawWrote($this->storageKey($storage), $sql); // also after a failure: part may be written
        }
    }

    /**
     * Execute a raw SQL write (UPDATE, DELETE, an upsert) and return the affected rows. A storage
     * that does not implement RunsSql throws UnsupportedStorage (a LogicException).
     *
     * @param array<int|string, mixed> $bindings Parameter bindings
     */
    public function execute(string $sql, array $bindings = [], string $storage = 'default'): int
    {
        $driver = $this->sql($storage, 'execute() needs a storage that runs SQL');

        try {
            return $driver->execute($sql, $bindings);
        } finally {
            $this->observer?->rawWrote($this->storageKey($storage), $sql); // also after a failure: part may be written
        }
    }

    /**
     * Runs $fn in one transaction per storage driver among $storages that has Transactions
     * (names aliasing one driver count once), nested in the given order: the first is
     * outermost, so the last commits first. A storage without transactions (JSON files) simply
     * runs the writes. A storage already in a transaction joins it: commit and rollback stay
     * with its owner. When $fn throws, every transaction this call began rolls back and the
     * failure is rethrown.
     *
     * A commit that fails after another storage this call began has committed throws
     * PartialCommit (naming the committed storages, the failure as previous): those writes stay.
     *
     * @template T
     * @param list<string> $storages storage names as configured
     * @param \Closure(): T $fn
     * @return T
     * @throws PartialCommit
     * @since 0.78
     */
    public function transaction(array $storages, \Closure $fn): mixed
    {
        /** @var array<int, array{0: string, 1: Transactions}> $drivers by driver object, first name kept */
        $drivers = [];
        foreach ($storages as $name) {
            $driver = $this->resolveDriver($name);
            if ($driver instanceof Transactions) {
                $drivers[spl_object_id($driver)] ??= [$name, $driver];
            }
        }

        /** @var list<string> $committed */
        $committed = [];
        $run = $fn;
        foreach (array_reverse($drivers) as [$name, $driver]) {
            $inner = $run;
            $run = static function () use ($name, $driver, $inner, &$committed): mixed {
                if ($driver->inTransaction()) {
                    return $driver->transaction($inner); // joined: the owner commits
                }
                $result = $driver->transaction($inner);
                $committed[] = $name;

                return $result;
            };
        }

        try {
            return $run();
        } catch (\Throwable $e) {
            // Only a commit can fail once an inner storage has committed ($fn has returned by then).
            if ($committed !== []) {
                throw new PartialCommit($committed, $e);
            }
            throw $e;
        }
    }

    private function sql(string $storage, string $needs): RunsSql
    {
        $driver = $this->resolveDriver($storage);
        if (!$driver instanceof RunsSql) {
            throw new UnsupportedStorage($storage, RunsSql::class, $needs);
        }

        return $driver;
    }

    /**
     * Save a model.
     *
     * A class marked #[Entity(type: …)] whose type the registry holds is saved as its type is
     * (writeClassType()).
     *
     * @throws ValidationException when $validate and the model fails its rules, and (validated
     *         or not) when the storage refuses a unique field's duplicate (nothing saved)
     * @throws \LogicException for an object loaded read-only (findAs(), queryAs())
     */
    public function save(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
    {
        $this->assertWritable($model);
        $meta = ModelMetadata::for($model::class);
        $def = $this->classType($meta);
        if ($def === null) {
            $this->stamp($model, $meta); // a class-backed type stores what its type says
        }
        if ($def !== null) {
            $this->writeClassType($model, $meta, $def, 'save', $validate, $rules, $scenario);

            return;
        }

        $this->giveStringId($model, $meta);
        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $driver = $this->resolveDriver($meta->storage);
        $data = $this->canonicalModel($meta, $this->modelData($meta, $model));
        $id = $data[$meta->idField] ?? null;
        $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

        $this->guardedModel($meta, $data, fn () => $driver->save($meta->table, $id ?? '', $data, $meta->idField));

        if ($isEmpty) {
            $this->takeInsertId($driver, $meta, $model);
        }
        $this->wroteModel($meta, $model);
    }

    /**
     * Insert a new model. Sets the auto-generated ID on the model.
     * Use this when you explicitly want INSERT behavior (not upsert).
     *
     * A model with an id that is stored already is never written over: insert() throws a
     * \RuntimeException, also when another writer stores the id between the check and the write
     * (on a storage with InsertsNew, as every built-in one has; any other storage is checked,
     * then written, and a second writer can get in between). Use insertIgnoring() to skip such a
     * model instead.
     *
     * @throws \RuntimeException when a record with the model's id exists
     * @throws \LogicException for an object loaded read-only (findAs(), queryAs())
     */
    public function insert(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
    {
        $this->insertModel($model, $validate, $rules, $scenario, false);
    }

    /**
     * Insert a new model only when no record has its id: true when it was written, false when the
     * id exists (the stored record is untouched, and no event or observer report is made for the
     * skipped model). For rows that may repeat, such as an event log or a visit counted once:
     * the check and the write are one step on a storage with InsertsNew, so two requests
     * inserting one id never overwrite each other. Validation, the generated id and events are
     * as for insert(). A model with an auto-increment id has no id to repeat and is always
     * written.
     *
     * A duplicate on another unique field is still a ValidationException.
     *
     * @throws \LogicException for an object loaded read-only (findAs(), queryAs())
     * @since 0.79.0
     */
    public function insertIgnoring(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): bool
    {
        return $this->insertModel($model, $validate, $rules, $scenario, true);
    }

    /**
     * Insert many models in one go: every model is inserted as insert() does (or, with
     * $ignoreDuplicates, as insertIgnoring() does), in one transaction on the storages that have
     * transactions. Returns the number of models written.
     *
     * Without $ignoreDuplicates a model whose id exists throws, and nothing of the batch is kept
     * on a storage with transactions (the SQL drivers); a storage without them (JSON files) keeps
     * the models written before it. Two models of the batch with one id count as a duplicate.
     *
     * @param iterable<Model> $models
     * @throws \RuntimeException when a record with a model's id exists and $ignoreDuplicates is false
     * @since 0.79.0
     */
    public function insertMany(iterable $models, bool $ignoreDuplicates = false): int
    {
        $models = is_array($models) ? array_values($models) : iterator_to_array($models, false);
        $storages = [];
        foreach ($models as $model) {
            $meta = ModelMetadata::for($model::class);
            $storages[] = $this->classType($meta)?->storage ?? $meta->storage;
        }

        return $this->transaction(array_values(array_unique($storages)), function () use ($models, $ignoreDuplicates): int {
            $written = 0;
            foreach ($models as $model) {
                if ($this->insertModel($model, true, [], null, $ignoreDuplicates)) {
                    $written++;
                }
            }

            return $written;
        });
    }

    /** @return bool false only for a skipped duplicate ($ignore); a duplicate throws otherwise */
    private function insertModel(Model $model, bool $validate, array $rules, ?string $scenario, bool $ignore): bool
    {
        $this->assertWritable($model);
        $meta = ModelMetadata::for($model::class);
        $def = $this->classType($meta);
        if ($def === null) {
            $this->stamp($model, $meta); // a class-backed type stores what its type says
        }
        if ($def !== null) {
            return $this->writeClassType($model, $meta, $def, $ignore ? 'ignore' : 'insert', $validate, $rules, $scenario);
        }

        $this->giveStringId($model, $meta);
        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $driver = $this->resolveDriver($meta->storage);
        if (self::hasStringId($meta)) {
            // A string id is never the storage's to give: the given one, or the new UUID.
            $data = $this->canonicalModel($meta, $this->modelData($meta, $model));
            $id = (string) $data[$meta->idField];
            if ($driver->exists($meta->table, $id, $meta->idField)) {
                return $this->duplicateId($model, $id, $ignore);
            }
            $written = true;
            $this->guardedModel($meta, $data, function () use ($driver, $meta, $id, $data, &$written): void {
                if ($driver instanceof InsertsNew) {
                    // The id is checked again in the write itself: a writer in between loses nothing.
                    $written = $driver->insertNew($meta->table, $data, $meta->idField);

                    return;
                }
                $driver->save($meta->table, $id, $data, $meta->idField);
            });
            if (!$written) {
                return $this->duplicateId($model, $id, $ignore);
            }
            $this->wroteModel($meta, $model);

            return true;
        }

        $data = $this->canonicalModel($meta, $this->modelData($meta, $model));

        // Force INSERT by passing empty ID (PdoDriver strips the ID field and does plain INSERT)
        $this->guardedModel($meta, $data, fn () => $driver->save($meta->table, 0, $data, $meta->idField));

        $this->takeInsertId($driver, $meta, $model);
        $this->wroteModel($meta, $model);

        return true;
    }

    /** The error insert() gives for an id that is stored: false for insertIgnoring(). */
    private function duplicateId(Model $model, string|int $id, bool $ignore): false
    {
        if ($ignore) {
            return false;
        }

        throw new \RuntimeException('Cannot insert ' . $model::class . " {$id}: a record with this id exists. Use save() or update().");
    }

    /**
     * A plain model whose string id is empty (or not set) gets a UUID v4 before its first write,
     * as a class-backed type gets its id: the storage gives only auto-increment numbers.
     */
    private function giveStringId(Model $model, ModelMetadata $meta): void
    {
        if (!self::hasStringId($meta)) {
            return;
        }
        $prop = new \ReflectionProperty($model, $meta->idField);
        if ($prop->isInitialized($model) && !self::emptyId($model->{$meta->idField})) {
            return;
        }
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $model->{$meta->idField} = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * #[Timestamps] on a plain model: the created property (createdAt or created_at) when it is
     * empty, the updated one (updatedAt or updated_at) always, each where the model has it, as
     * the property's type takes the time: a date, 'Y-m-d H:i:s' text or the Unix time.
     */
    private function stamp(Model $model, ModelMetadata $meta): void
    {
        if (!$meta->hasTimestamps) {
            return;
        }
        $now = new \DateTimeImmutable();
        foreach ([[Timestamps::CREATED_NAMES, false], [Timestamps::UPDATED_NAMES, true]] as [$names, $always]) {
            $name = Timestamps::propertyOf($model::class, $names);
            if ($name === null) {
                continue;
            }
            $prop = new \ReflectionProperty($model, $name);
            if (!$prop->isPublic() || $prop->isStatic() || $prop->isReadOnly()) {
                continue;
            }
            if (!$always && $prop->isInitialized($model) && $model->{$name} !== null && $model->{$name} !== '' && $model->{$name} !== 0) {
                continue;
            }
            $type = $prop->getType();
            $kind = $type === null ? 'mixed' : ($type instanceof \ReflectionNamedType ? $type->getName() : null);
            $value = match ($kind) {
                'string', 'mixed' => $now->format('Y-m-d H:i:s'),
                'int' => $now->getTimestamp(),
                \DateTimeImmutable::class, \DateTimeInterface::class => $now, // stored as text (DateTimeTransformer)
                default => null, // \DateTime and others: no value that is stored and read back
            };
            if ($value !== null) {
                $model->{$name} = $value;
            }
        }
    }

    /**
     * Update an existing model. Throws if ID is empty.
     * Use this when you explicitly want UPDATE behavior (not upsert).
     *
     * @throws \LogicException for an object loaded read-only (findAs(), queryAs())
     */
    public function update(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
    {
        $this->assertWritable($model);
        $meta = ModelMetadata::for($model::class);
        $def = $this->classType($meta);
        if ($def === null) {
            $this->stamp($model, $meta); // a class-backed type stores what its type says
        }
        if ($def !== null) {
            $this->writeClassType($model, $meta, $def, 'update', $validate, $rules, $scenario);

            return;
        }

        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $driver = $this->resolveDriver($meta->storage);
        $data = $this->canonicalModel($meta, $this->modelData($meta, $model));
        $id = $data[$meta->idField] ?? null;

        if (self::emptyId($id)) {
            throw new \RuntimeException("Cannot update model without an ID. Use insert() for new records.");
        }

        $this->guardedModel($meta, $data, fn () => $driver->save($meta->table, $id, $data, $meta->idField));
        $this->reportWrite($meta->storage, $meta->table, (string) $id);
    }

    /**
     * Delete a model by class+ID or by model instance.
     *
     * @param class-string<Model>|Model $modelClassOrInstance
     * @throws \LogicException for an object loaded read-only (findAs(), queryAs())
     */
    public function delete(string|Model $modelClassOrInstance, string|int|null $id = null): void
    {
        if ($modelClassOrInstance instanceof Model) {
            $this->assertWritable($modelClassOrInstance);
            $meta = ModelMetadata::for($modelClassOrInstance::class);
            $driver = $this->resolveDriver($meta->storage);
            $data = $modelClassOrInstance->toArray();
            $modelId = $data[$meta->idField] ?? throw new \RuntimeException("Model missing ID field [{$meta->idField}].");
            $driver->delete($meta->table, $modelId, $meta->idField);
            $this->reportWrite($meta->storage, $meta->table, (string) $modelId);
            return;
        }

        // Original path: class + ID
        if ($id === null) {
            throw new \RuntimeException('ID is required when deleting by class name.');
        }
        $meta = ModelMetadata::for($modelClassOrInstance);
        $driver = $this->resolveDriver($meta->storage);
        $driver->delete($meta->table, $id, $meta->idField);
        $this->reportWrite($meta->storage, $meta->table, (string) $id);
    }

    private static function emptyId(mixed $id): bool
    {
        return $id === null || $id === '' || $id === 0 || $id === '0';
    }

    /** Sets the id the storage generated for the model just inserted, if it gave one. */
    private function takeInsertId(StorageDriver $driver, ModelMetadata $meta, Model $model): void
    {
        $newId = $driver->lastInsertId();
        if ($newId !== '' && $newId !== 0) {
            $model->{$meta->idField} = is_int($newId) ? $newId : (is_numeric($newId) ? (int) $newId : $newId);
        }
    }

    /**
     * A save, insert or update of a class-backed type, as saveType() writes its record: the
     * type's validation (its rules, a translatable field per locale, unique fields per locale,
     * bounded columns) plus the class's own (scenario rules, rules()), the values as saveType()
     * stores them, and nothing else: only the id and the type's fields are written, so the
     * columns the class does not declare (Folio's _version, _publish_at, …) stay as stored.
     * An empty string id gets the id Folio gives a new record (32 hex characters).
     *
     * @param 'save'|'insert'|'ignore'|'update' $mode 'ignore': insert() that skips an id that exists
     * @return bool false only for a skipped duplicate (mode 'ignore')
     */
    private function writeClassType(Model $model, ModelMetadata $meta, TypeDefinition $def, string $mode, bool $validate, array $rules, ?string $scenario): bool
    {
        $values = $this->classTypeValues($model, $def);
        $record = new DynamicRecord($def, $values);
        $driver = $this->resolveDriver($def->storage);

        if ($validate) {
            $this->validateDynamicRecord($record, $rules, $model, $this->classRules($model, $meta, $scenario));
        }

        $id = $record->toArray()[$def->idField] ?? null;
        $given = !self::emptyId($id);
        if (!$given) {
            if ($mode === 'update') {
                throw new \RuntimeException("Cannot update model without an ID. Use insert() for new records.");
            }
            if (self::hasStringId($meta)) {
                $id = bin2hex(random_bytes(16));
                $model->{$def->idField} = $id;
                $record->set($def->idField, $id);
            }
        }
        $inserting = $mode === 'insert' || $mode === 'ignore';
        if ($inserting && $given && $driver->findOne($def->table, $id, $def->idField) !== null) {
            return $this->duplicateId($model, $id, $mode === 'ignore');
        }

        $data = $this->storageData($record);
        $versionField = $this->versionFieldOf($def);
        $written = true;
        $this->guarded($record, function () use ($driver, $def, $id, $data, $versionField, $inserting, $given, &$written): void {
            if ($inserting && $given && $driver instanceof InsertsNew) {
                // Checked again in the write itself: a writer in between is a duplicate, not an overwrite.
                $written = $driver->insertNew($def->table, $this->storageValues($def, $versionField === null ? $data : [...$data, $versionField => 1]), $def->idField);

                return;
            }
            if (self::emptyId($id)) {
                // An auto-increment id: the storage gives it (a first write, version 1).
                $driver->save($def->table, 0, $this->storageValues($def, $versionField === null ? $data : [...$data, $versionField => 1]), $def->idField);

                return;
            }
            if ($versionField !== null) {
                $this->saveClassBumped($driver, $def, $id, $data, $versionField);

                return;
            }
            $driver->save($def->table, $id, $this->storageValues($def, $data), $def->idField);
        });
        if (!$written) {
            return $this->duplicateId($model, $id, $mode === 'ignore');
        }

        if (self::emptyId($id)) {
            $this->takeInsertId($driver, $meta, $model);
        }
        $this->forgetSkipped($model, array_keys($values));
        $this->wroteModel($meta, $model);

        return true;
    }

    /**
     * A class-backed save of a type with a version field: its fields written over the stored record
     * (the other columns kept) with the stored version + 1 when a value differs (contentChanged()),
     * else with the stored version; checked in the write (ConditionalUpdates) and tried again when
     * another save got in between; a first write is version 1. As saveBumped(), a record busy on
     * every try is written anyway.
     *
     * @param array<string, mixed> $data
     */
    private function saveClassBumped(StorageDriver $driver, TypeDefinition $def, string|int $id, array $data, string $versionField): void
    {
        for ($attempt = 1; ; $attempt++) {
            $stored = $driver->findOne($def->table, $id, $def->idField);
            $value = $stored[$versionField] ?? null;
            $current = $value === null || $value === '' ? null : (int) $value;
            $changed = $stored === null || $this->contentChanged($def, $data, $stored, $versionField);
            // Unchanged: the stored version stays (none stays none).
            $version = $changed ? [$versionField => ($current ?? 0) + 1] : ($current === null ? [] : [$versionField => $current]);
            if ($stored === null || !$driver instanceof ConditionalUpdates || $attempt >= self::BUMP_ATTEMPTS) {
                $driver->save($def->table, $id, $this->storageValues($def, [...$data, ...$version]), $def->idField);

                return;
            }
            $fields = array_diff_key($this->storageValues($def, [...$data, ...$version]), [$def->idField => true]);
            if ($driver->updateIfVersion($def->table, $id, $fields, $versionField, $current, $def->idField)) {
                return;
            }
        }
    }

    /**
     * The values a class-backed model writes: its id and the type's fields, as the properties
     * hold them (a property that is no field is no column).
     *
     * @return array<string, mixed>
     */
    private function classTypeValues(Model $model, TypeDefinition $def): array
    {
        $vars = get_object_vars($model); // public, initialized properties (called from outside the class)
        $skipped = $this->skipped !== null && isset($this->skipped[$model]) ? $this->skipped[$model] : [];
        $values = [];
        foreach ([$def->idField, ...array_keys($def->fields)] as $name) {
            if (!array_key_exists($name, $vars)) {
                continue;
            }
            if (array_key_exists($name, $skipped) && $skipped[$name] === [true, $vars[$name]]) {
                continue; // the read could not take the stored value and the code left the default: keep what is stored
            }
            $values[$name] = $vars[$name];
        }

        return $values;
    }

    /**
     * Per model read by hydrate(), the fields whose stored value the property could not take,
     * with the value the property was left with ([initialized, value]). A typed save writes
     * such a field only once the code changed it: else the default would replace the stored
     * value nobody looked at.
     *
     * @var \WeakMap<Model, array<string, array{bool, mixed}>>|null
     */
    private ?\WeakMap $skipped = null;

    /** @param list<string> $written fields a save wrote: from now on the property's value is what is stored */
    private function forgetSkipped(Model $model, array $written): void
    {
        if ($this->skipped === null || !isset($this->skipped[$model])) {
            return;
        }
        $left = array_diff_key($this->skipped[$model], array_flip($written));
        if ($left === []) {
            unset($this->skipped[$model]);
        } else {
            $this->skipped[$model] = $left;
        }
    }

    /**
     * The class's own rules, added to its type's: the #[Validate] rules for the scenario (as
     * validateModel() takes them, so `on:` rules apply) and rules().
     *
     * @return array<string, list<mixed>>
     */
    private function classRules(Model $model, ModelMetadata $meta, ?string $scenario): array
    {
        $rules = $scenario !== null ? $meta->validationRulesForScenario($scenario) : $meta->validationRules;
        if (method_exists($model, 'rules')) {
            foreach ($model->rules() as $name => $fieldRules) {
                $rules[$name] = [...($rules[$name] ?? []), ...(array) $fieldRules];
            }
        }

        return $rules;
    }

    /** Whether the model's id property holds a string (Folio's ids), not an auto-increment number. */
    private static function hasStringId(ModelMetadata $meta): bool
    {
        if (!property_exists($meta->modelClass, $meta->idField)) {
            return false;
        }
        $type = (new \ReflectionProperty($meta->modelClass, $meta->idField))->getType();

        return $type instanceof \ReflectionNamedType && $type->getName() === 'string';
    }

    /** @var array<class-string, array<string, ?\ReflectionType>> */
    private array $propertyTypes = [];

    /**
     * A model from a stored row. An ordinary model is filled as it is; a class-backed type
     * reads what its type stores: a translatable field's locale map (JSON text on SQL) as an
     * array, and numbers and yes/no (text on JSON storage, integers on SQL) as the property's
     * type, and an array field's JSON text (SQL) as an array. A NULL a property cannot take
     * leaves its default; so does any value it cannot take (but text an array property cannot
     * take throws UnreadableValue: its default would lose a list), and a typed save then keeps the
     * stored value until the code sets the property (see $skipped).
     *
     * @param array<string, mixed> $data
     */
    private function hydrate(ModelMetadata $meta, ?TypeDefinition $def, array $data): Model
    {
        $model = new ($meta->modelClass)();
        if ($def === null) {
            $model->fill($data);

            return $model;
        }

        $types = $this->propertyTypes[$meta->modelClass] ??= $this->publicPropertyTypes($meta->modelClass);
        $translatable = array_flip($def->translatableFields());
        $skipped = [];
        foreach ($data as $name => $value) {
            if (!array_key_exists($name, $types)) {
                continue;
            }
            if (isset($meta->transformers[$name])) {
                $value = $meta->transformers[$name]->fromStorage($value);
            }
            if (isset($translatable[$name]) && is_string($value)) {
                $map = LocaleValue::map($value, $def->locales);
                $value = $map === [$def->locales->default => $value] ? $value : $map;
            }
            if ($value === 'null' && self::takesArray($types[$name])) {
                $value = null; // JSON null: an array field with no value
            }
            [$assign, $converted] = self::asPropertyType($types[$name], $value);
            if ($assign) {
                $model->{$name} = $converted;
            } elseif (self::losesAList($types[$name], $value)) {
                $id = $data[$meta->idField] ?? null;
                throw new UnreadableValue($def->key, $name, is_scalar($id) ? (string) $id : null, $meta->modelClass . '::$' . $name);
            } else {
                $skipped[] = $name;
            }
        }
        if ($skipped !== []) {
            $vars = get_object_vars($model);
            $this->skipped ??= new \WeakMap();
            $this->skipped[$model] = array_combine($skipped, array_map(
                static fn (string $name): array => [array_key_exists($name, $vars), $vars[$name] ?? null],
                $skipped,
            ));
        }

        return $model;
    }

    /** @return array<string, ?\ReflectionType> */
    private function publicPropertyTypes(string $class): array
    {
        $types = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!$prop->isStatic() && !$prop->isReadOnly()) {
                $types[$prop->getName()] = $prop->getType();
            }
        }

        return $types;
    }

    /**
     * A stored value as the property's type: whether to assign it, and the value. A value the
     * type takes is assigned as it is; else it is converted where that is lossless (numeric
     * text to a number, a number to text, '0', 'false' and '' to false, JSON text of an array
     * to the array). A value that cannot be converted (text in a number field, 4.5 for an int)
     * leaves the property's default, as a NULL the property cannot take does: one odd row
     * never breaks a read the type would make.
     *
     * @return array{bool, mixed}
     */
    private static function asPropertyType(?\ReflectionType $type, mixed $value): array
    {
        if ($type === null) {
            return [true, $value];
        }
        $names = $type instanceof \ReflectionUnionType
            ? array_map(static fn (\ReflectionType $t): string => $t instanceof \ReflectionNamedType ? $t->getName() : '', $type->getTypes())
            : [$type instanceof \ReflectionNamedType ? $type->getName() : ''];
        if ($value === null) {
            return $type->allowsNull() ? [true, null] : [false, null];
        }
        if (in_array('mixed', $names, true) || self::takes($names, $value)) {
            return [true, $value];
        }
        foreach ($names as $name) {
            $converted = match ($name) {
                'int' => is_numeric($value) && (float) $value == (int) $value ? (int) $value : (is_bool($value) ? (int) $value : null),
                'float' => is_numeric($value) ? (float) $value : null,
                'bool' => is_scalar($value) ? !in_array(is_string($value) ? strtolower(trim($value)) : $value, ['0', 'false', '', 0, 0.0], true) : null,
                'string' => is_int($value) || is_float($value) ? (string) $value : null,
                // SQL storage holds an array field (assets, blocks, a multiple relation) as JSON text
                'array' => is_string($value) && is_array($decoded = json_decode($value, true)) ? $decoded : null,
                default => null,
            };
            if ($converted !== null) {
                return [true, $converted];
            }
        }
        if ($value === '') {
            return self::asPropertyType($type, null); // an empty number field: as NULL
        }

        return [false, null];
    }

    /**
     * Whether a value an `array` property cannot take holds data that its default would lose: any
     * value but null and '' (since 0.77 such a read throws UnreadableValue instead of skipping;
     * JSON "null" was read as null before).
     */
    private static function losesAList(?\ReflectionType $type, mixed $value): bool
    {
        return $value !== null && $value !== '' && self::takesArray($type);
    }

    /** Whether the property's type (or one in its union) is array. */
    private static function takesArray(?\ReflectionType $type): bool
    {
        if ($type === null) {
            return false;
        }
        $names = $type instanceof \ReflectionUnionType
            ? array_map(static fn (\ReflectionType $t): string => $t instanceof \ReflectionNamedType ? $t->getName() : '', $type->getTypes())
            : [$type instanceof \ReflectionNamedType ? $type->getName() : ''];

        return in_array('array', $names, true);
    }

    /** @param list<string> $names whether a property of these built-in types takes $value as it is */
    private static function takes(array $names, mixed $value): bool
    {
        $kind = match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => null,
        };
        if ($kind === null) {
            foreach ($names as $name) {
                if ($name !== '' && $value instanceof $name) {
                    return true;
                }
            }

            return false;
        }

        return in_array($kind, $names, true)
            || ($kind === 'int' && in_array('float', $names, true))
            || ($kind === 'bool' && (in_array('true', $names, true) && $value === true || in_array('false', $names, true) && $value === false));
    }

    /**
     * Find a single dynamic record by type and ID.
     */
    public function findType(string $type, string $id): ?DynamicRecord
    {
        return $this->findIn($this->requireTypeRegistry()->get($type), $id);
    }

    /**
     * Find a record of a given definition (e.g. one built in code, such as a
     * shadow table) without going through the registry.
     */
    public function findIn(TypeDefinition $typeDef, string|int $id): ?DynamicRecord
    {
        $data = $this->resolveDriver($typeDef->storage)->findOne($typeDef->table, $id, $typeDef->idField);
        $this->reportRead($typeDef->storage, $typeDef->table, (string) $id);

        return $data === null ? null : DynamicRecord::fromArray($typeDef, $data);
    }

    /**
     * Start a query for a dynamic type.
     */
    public function queryType(string $type): QueryBuilder
    {
        return $this->queryIn($this->requireTypeRegistry()->get($type));
    }

    public function queryIn(TypeDefinition $typeDef): QueryBuilder
    {
        return QueryBuilder::forType($this->resolveDriver($typeDef->storage), $typeDef, $this);
    }

    /**
     * Save a dynamic record.
     *
     * Unique fields: validation checks them (Unique), and the storage guards them whether
     * validated or not (the unique index on a storage with UniqueIndexes; translatable fields and
     * other storages are checked in the save, under the type's lock where the storage has one,
     * ExclusiveWrites). A duplicate is a ValidationException
     * with validation.unique on the field ("field.locale" for another locale's value).
     *
     * @throws ValidationException (nothing saved)
     */
    public function saveType(DynamicRecord $record, bool $validate = true, array $rules = []): void
    {
        $typeDef = $record->getType();

        if ($validate) {
            $this->validateDynamicRecord($record, $rules);
        }

        $driver = $this->resolveDriver($typeDef->storage);
        $id = $record->getId();

        if ($id === null) {
            throw new \RuntimeException("DynamicRecord must have an ID ({$typeDef->idField}) before saving.");
        }

        $versionField = $this->versionFieldOf($typeDef);
        if ($versionField === null) {
            $this->guarded($record, fn () => $driver->save($typeDef->table, $id, $this->storageData($record), $typeDef->idField));
        } else {
            $this->guarded($record, fn () => $this->saveBumped($driver, $record, $id, $versionField));
        }
        $this->reportWrite($typeDef->storage, $typeDef->table, (string) $id);
    }

    /**
     * Whether a value $data writes differs from the stored row: the keys written, but the id, the
     * version field and the type's other keys that are no content (VersionFields::nonContent()).
     * Compared as stored, the same on every driver: JSON text decoded, empty values (null, '', an
     * empty list) alike, numbers of number fields by value, yes/no fields by their truth, other
     * scalars as text.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $stored
     */
    private function contentChanged(TypeDefinition $def, array $data, array $stored, string $versionField): bool
    {
        $skip = array_flip([$def->idField, $versionField, ...($this->versionFields?->nonContent($def) ?? [])]);
        foreach (array_diff_key($data, $skip) as $key => $value) {
            $kind = isset($def->fields[$key]) ? $this->fieldKinds()->columnKindOf($def->fields[$key]) : null;
            if (self::asStored($value, $kind) !== self::asStored($stored[$key] ?? null, $kind)) {
                return true;
            }
        }

        return false;
    }

    /** A value as contentChanged() compares it. */
    private static function asStored(mixed $value, ?ColumnKind $kind): mixed
    {
        if (is_string($value) && $value !== '' && ($value[0] === '[' || $value[0] === '{')) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : $value;
        }
        if ($kind === ColumnKind::Boolean) {
            return in_array($value, [true, 1, '1'], true);
        }
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_array($value)) {
            $value = array_map(static fn (mixed $item): mixed => self::asStored($item, null), $value);
            if (!array_is_list($value)) {
                ksort($value);
            }

            return $value;
        }
        if ($kind === ColumnKind::Number && is_numeric($value)) {
            return (float) $value;
        }

        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => $value,
        };
    }

    /** How often a bumping save tries the stored version + 1 before it writes over a busy record. */
    private const int BUMP_ATTEMPTS = 3;

    /**
     * saveType() of a type with a version field (VersionFields): written as the stored version + 1
     * when a written value differs from the stored one (contentChanged()), else with the stored
     * version; checked in the write (VersionedStorage) and tried again when another save got in
     * between; the record holds the version written. A record saved by others on every try is
     * written anyway (deciding on what is stored then): a save never fails for being busy.
     */
    private function saveBumped(StorageDriver $driver, DynamicRecord $record, string|int $id, string $versionField): void
    {
        $def = $record->getType();
        $had = array_key_exists($versionField, $record->toArray());
        $before = $record->get($versionField);
        try {
            for ($attempt = 1; ; $attempt++) {
                $stored = $driver->findOne($def->table, $id, $def->idField);
                $current = (int) ($stored[$versionField] ?? 0);
                $changed = $stored === null || $this->contentChanged($def, $this->storageData($record), $stored, $versionField);
                $record->set($versionField, $changed ? $current + 1 : $current);
                if (!$driver instanceof VersionedStorage || $attempt >= self::BUMP_ATTEMPTS) {
                    $driver->save($def->table, $id, $this->storageData($record), $def->idField);

                    return;
                }
                if ($driver->saveIfVersion($def->table, $id, $this->storageData($record), $versionField, $current, $def->idField)) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            $had ? $record->set($versionField, $before) : $record->remove($versionField);
            throw $e;
        }
    }

    /**
     * Save a dynamic record only if its stored version is still $expected (compare-and-set):
     * the record is written with $versionField = $next (default $expected + 1), and of two
     * writers holding the same version exactly one gets through. A missing or empty stored
     * version counts as 0; a record not stored yet is inserted when $expected is 0.
     *
     * Atomic on every built-in driver (VersionedStorage). Any other driver gets a plain
     * read, compare and write, which is not (an E_USER_NOTICE says so, once per driver class).
     * SQL drivers match only a canonical integer string as the stored version ('5', not '05').
     *
     * @return bool false when someone else saved in between: nothing was written and the
     *              record's version is left as it was (removed again if it had none)
     * @throws ValidationException when $validate and the record fails its rules (nothing saved)
     */
    public function saveTypeIfVersion(DynamicRecord $record, int $expected, ?int $next = null, string $versionField = '_version', bool $validate = true, array $rules = []): bool
    {
        $typeDef = $record->getType();
        if ($validate) {
            $this->validateDynamicRecord($record, $rules);
        }
        $id = $record->getId() ?? throw new \RuntimeException("DynamicRecord must have an ID ({$typeDef->idField}) before saving.");
        $driver = $this->resolveDriver($typeDef->storage);

        $had = array_key_exists($versionField, $record->toArray());
        $before = $record->get($versionField);
        $record->set($versionField, $next ?? $expected + 1);

        try {
            $written = $this->guarded($record, function () use ($driver, $typeDef, $id, $record, $versionField, $expected): bool {
                if ($driver instanceof VersionedStorage) {
                    return $driver->saveIfVersion($typeDef->table, $id, $this->storageData($record), $versionField, $expected, $typeDef->idField);
                }
                self::noticeNotVersioned($driver);
                $stored = $driver->findOne($typeDef->table, $id, $typeDef->idField);
                $written = $stored === null ? $expected === 0 : (int) ($stored[$versionField] ?? 0) === $expected;
                if ($written) {
                    $driver->save($typeDef->table, $id, $this->storageData($record), $typeDef->idField);
                }

                return $written;
            });
        } catch (\Throwable $e) {
            $had ? $record->set($versionField, $before) : $record->remove($versionField);
            throw $e;
        }

        if (!$written) {
            $had ? $record->set($versionField, $before) : $record->remove($versionField);
        } else {
            $this->reportWrite($typeDef->storage, $typeDef->table, (string) $id);
        }

        return $written;
    }

    /** Reports a model write with the id it has now (null when the storage gave none). */
    private function wroteModel(ModelMetadata $meta, Model $model): void
    {
        if ($this->observer === null) {
            return;
        }
        $id = $model->toArray()[$meta->idField] ?? null;
        $this->reportWrite($meta->storage, $meta->table, (is_int($id) || is_string($id)) && $id !== 0 && $id !== '0' ? $id : null);
    }

    /** @return array<string, mixed> */
    private function storageData(DynamicRecord $record): array
    {
        return $this->storageValues($record->getType(), $record->toArray());
    }

    /**
     * A row of this type as it is written, the same whoever saves it (Folio, the CLI, a script),
     * so an unchanged record is written to the same bytes:
     * - the keys in the type's field order, then the system keys (the id field when it is no
     *   field, then the keys starting with "_", in their given order), then any other key in its
     *   given order; no key is dropped;
     * - a list field (FieldKinds::list()) holds a list, also when given as JSON text or a comma
     *   string; a number field's numeric text is a number (int when whole); a yes/no field's
     *   '1'/'0'/1/0/'true'/'false' is a bool. A value that does not convert stays as it is, and so
     *   does a translatable field (a map per locale) and one the type transforms itself;
     * - an empty string in a number, yes/no or date field is NULL (those columns hold no empty
     *   text), and so is an empty unique value (NULL is never a duplicate in a unique index, ''
     *   would be); other empty text stays ''.
     * SQL storage stores a list as JSON text, as before. For callers writing rows to a driver
     * themselves (an import), as saveType() does.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function storageValues(TypeDefinition $def, array $data): array
    {
        $kinds = $this->fieldKinds();
        foreach ($def->fields as $name => $field) {
            if (!array_key_exists($name, $data)) {
                continue;
            }
            $kind = $kinds->columnKindOf($field);
            if ($data[$name] === '') {
                if (in_array($kind, [ColumnKind::Number, ColumnKind::Boolean, ColumnKind::Date], true) || ($field->unique !== false && $kind !== ColumnKind::Text)) {
                    $data[$name] = null;
                }
                continue;
            }
            if ($field->translatable || isset($def->transformers[$name])) {
                continue;
            }
            $data[$name] = match (true) {
                $kinds->isList($field->type) => self::asList($data[$name]),
                $kind === ColumnKind::Number => self::asNumber($data[$name]),
                $kind === ColumnKind::Boolean => self::asBool($data[$name]),
                default => $data[$name],
            };
        }

        return self::canonicalOrder($data, array_keys($def->fields), $def->idField);
    }

    /**
     * $data with the keys in $fields first (in that order), then $idField when it is none of them
     * and the keys starting with "_", then the rest; every group but the first in its given order.
     *
     * @param array<string, mixed> $data
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private static function canonicalOrder(array $data, array $fields, string $idField): array
    {
        $first = [];
        foreach ($fields as $name) {
            if (array_key_exists($name, $data)) {
                $first[$name] = $data[$name];
            }
        }
        $system = [];
        if (array_key_exists($idField, $data) && !array_key_exists($idField, $first)) {
            $system[$idField] = $data[$idField];
        }
        $rest = [];
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $first) || array_key_exists($key, $system)) {
                continue;
            }
            if (is_string($key) && str_starts_with($key, '_')) {
                $system[$key] = $value;
            } else {
                $rest[$key] = $value;
            }
        }

        return $first + $system + $rest;
    }

    /**
     * A plain model's row in a stable key order: the fields of its metadata, then the rest.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function canonicalModel(ModelMetadata $meta, array $data): array
    {
        return self::canonicalOrder($data, array_map(strval(...), array_keys($meta->fields)), $meta->idField);
    }

    /** A list value as a list: JSON text of a list, or a legacy comma string ("a, b"); anything else as it is. */
    private static function asList(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $text = trim($value);
        if ($text === '' || $text === 'null' || $text[0] === '{') {
            return $value;
        }
        if ($text[0] === '[') {
            $decoded = json_decode($text, true);

            return is_array($decoded) && array_is_list($decoded) ? $decoded : $value;
        }
        $parts = array_values(array_filter(array_map(trim(...), explode(',', $text)), static fn (string $part): bool => $part !== ''));

        return $parts === [] ? $value : $parts;
    }

    /** Numeric text as a number (an int when whole and in range, else a float); anything else as it is. */
    private static function asNumber(mixed $value): mixed
    {
        if (!is_string($value) || !is_numeric($value)) {
            return $value;
        }
        $text = trim($value);
        // Whole numbers of up to 18 digits fit an int on every 64-bit PHP; longer ones are floats.
        if (preg_match('/^[+-]?\d+$/', $text) === 1 && strlen(ltrim(ltrim($text, '+-'), '0')) <= 18) {
            return (int) $text;
        }
        $float = (float) $text;

        return is_finite($float) ? $float : $value;
    }

    /** '1', '0', 1, 0, 'true' and 'false' as a bool; anything else as it is. */
    private static function asBool(mixed $value): mixed
    {
        return match ($value) {
            '1', 1, 'true' => true,
            '0', 0, 'false' => false,
            default => $value,
        };
    }

    /** @var array<class-string, true> driver classes already told about */
    private static array $noticedNotVersioned = [];

    /** Once per driver class: its version-checked saves are a read, compare and write, not atomic. */
    private static function noticeNotVersioned(StorageDriver $driver): void
    {
        $class = $driver::class;
        if (isset(self::$noticedNotVersioned[$class])) {
            return;
        }
        self::$noticedNotVersioned[$class] = true;
        trigger_error(
            "Storage driver {$class} does not implement Preflow\\Data\\VersionedStorage: saveTypeIfVersion() reads, compares and writes, which two concurrent saves can both pass.",
            E_USER_NOTICE,
        );
    }

    /**
     * Delete a dynamic record by type and ID.
     */
    public function deleteType(string $type, string|int $id): void
    {
        $this->deleteIn($this->requireTypeRegistry()->get($type), $id);
    }

    public function deleteIn(TypeDefinition $typeDef, string|int $id): void
    {
        $this->resolveDriver($typeDef->storage)->delete($typeDef->table, $id, $typeDef->idField);
        $this->reportWrite($typeDef->storage, $typeDef->table, (string) $id);
    }

    /**
     * Counts on the row $id of a type in one step: inserts it as [$idField => $id] + $insert +
     * [$field => $by] when missing, restarts it when $reset matches (writes $reset->set and
     * $field = $by), else adds $by (see AtomicCounters::increment()). Returns the counter as read
     * right after the write. Writes the named columns only: no validation, no version bump.
     *
     * Atomic on a storage with AtomicCounters (the built-in drivers). On another, a read, an
     * addition and a write: under the type's lock when the storage has ExclusiveWrites, else
     * plainly, which is not atomic (two concurrent increments may count once).
     *
     * @param array<string, scalar|null> $insert the other columns of a new row
     * @throws \InvalidArgumentException when $insert names $field or the id, or $reset->set names $field
     * @since 0.78
     */
    public function incrementIn(TypeDefinition $def, string|int $id, string $field, int $by, array $insert, ?CounterReset $reset = null): int
    {
        $driver = $this->resolveDriver($def->storage);
        if ($driver instanceof AtomicCounters) {
            $value = $driver->increment($def->table, $id, $field, $by, $insert, $reset, $def->idField);
        } else {
            $step = static fn (): int => Counters::step($driver, $def->table, $id, $field, $by, $insert, $reset, $def->idField);
            $value = $driver instanceof ExclusiveWrites ? $driver->exclusive($def->table, $step) : $step();
        }
        $this->reportWrite($def->storage, $def->table, (string) $id);

        return $value;
    }

    /**
     * Deletes many records of a type: in a few statements on a storage that implements
     * BulkDeletes (the built-in drivers), one by one on any other. Repeated ids count once.
     *
     * @param list<string|int> $ids
     * @since 0.77
     */
    public function deleteManyIn(TypeDefinition $typeDef, array $ids): void
    {
        $ids = array_values(array_unique(array_map(strval(...), $ids)));
        if ($ids === []) {
            return;
        }
        $driver = $this->resolveDriver($typeDef->storage);
        if ($driver instanceof BulkDeletes) {
            $driver->deleteMany($typeDef->table, $ids, $typeDef->idField);
        } else {
            foreach ($ids as $id) {
                $driver->delete($typeDef->table, $id, $typeDef->idField);
            }
        }
        foreach ($ids as $id) {
            $this->reportWrite($typeDef->storage, $typeDef->table, $id);
        }
    }

    /**
     * Validate a dynamic record as saveType() would, without saving it.
     *
     * @throws ValidationException
     */
    public function validate(DynamicRecord $record, array $rules = []): void
    {
        $this->validateDynamicRecord($record, $rules);
    }

    /**
     * Check only the record's unique fields against the other records (as validation does): for
     * a save that skips the other rules, such as a draft.
     *
     * @throws ValidationException with validation.unique on each taken field
     */
    public function validateUnique(DynamicRecord $record): void
    {
        $def = $record->getType();
        $taken = $this->takenFields($this->uniqueChecks($def, $record->toArray(), $record->getId(), $def->uniqueFields()));
        if ($taken !== []) {
            throw $this->uniqueFailure($taken);
        }
    }

    /**
     * Check what the storage itself would refuse, for a save that skips the other rules (a
     * draft, a restored revision): a bounded string column (a unique or indexed string, a unique
     * field's scope) longer than its length, and a unique value another record has. A length is
     * measured as text, also for a number-like value. Runs without a validator factory too.
     *
     * @throws ValidationException with validation.max.string or validation.unique on each field
     */
    public function validateStorable(DynamicRecord $record): void
    {
        $def = $record->getType();
        $data = $record->toArray();
        $failures = [];
        foreach ($this->fieldKinds()->lengths($def) as $name => $length) {
            $value = $data[$name] ?? null;
            if (is_scalar($value) && mb_strlen((string) $value) > $length) {
                $failures[$name] = new Message('validation.max.string', ['max' => (string) $length]);
            }
        }
        $checks = array_diff_key($this->uniqueChecks($def, $data, $record->getId(), $def->uniqueFields()), $failures);
        foreach ($this->takenFields($checks) as $key) {
            $failures[$key] = new Message('validation.unique');
        }
        if ($failures !== []) {
            throw $this->failure($failures);
        }
    }

    private function validateModel(Model $model, array $extraRules = [], ?string $scenario = null): void
    {
        if ($this->validatorFactory === null) {
            return;
        }

        $meta = ModelMetadata::for($model::class);
        $rules = $scenario !== null
            ? $meta->validationRulesForScenario($scenario)
            : $meta->validationRules;

        if (method_exists($model, 'rules')) {
            $rules = array_merge($rules, $model->rules());
        }

        if ($extraRules !== []) {
            $rules = array_merge($rules, $extraRules);
        }

        $data = $model->toArray();
        $id = $data[$meta->idField] ?? null;
        $id = $id === '' || $id === 0 || $id === '0' ? null : $id;
        foreach ($meta->uniqueFields() as $field => $scope) {
            $rules[$field] = [...($rules[$field] ?? []), new Unique($this, $model::class, $field, $scope, $id)];
        }

        if ($rules === []) {
            return;
        }

        $validator = $this->validatorFactory->make($rules, $data, subject: $model);
        $result = $validator->validate();

        if ($result->fails()) {
            throw new ValidationException($result);
        }
    }

    /**
     * @param object|null $subject what the rules validate (the record, or the model of a class-backed type)
     * @param array<string, list<mixed>> $ownRules a class-backed type's own rules, added to its type's per field
     */
    private function validateDynamicRecord(DynamicRecord $record, array $extraRules = [], ?object $subject = null, array $ownRules = []): void
    {
        if ($this->validatorFactory === null) {
            return;
        }

        $typeDef = $record->getType();
        $rules = $typeDef->validationRules();
        foreach ($ownRules as $name => $fieldRules) {
            foreach ($fieldRules as $rule) {
                if (!in_array($rule, $rules[$name] ?? [], true)) {
                    $rules[$name][] = $rule;
                }
            }
        }

        // A unique or indexed string, and a unique field's scope, is a bounded column: it may not outgrow it.
        foreach ($this->fieldKinds()->lengthRules($typeDef) as $name => $lengthRule) {
            $rules[$name] = [...($rules[$name] ?? []), $lengthRule];
        }

        if ($extraRules !== []) {
            $rules = array_merge($rules, $extraRules);
        }

        [$rules, $data] = $this->localizedValidation($typeDef, $rules, $record->toArray());

        // Unique fields, per locale for translatable ones; not a field the extra rules leave
        // unvalidated (an empty list: Folio's read-only fields).
        $unique = array_filter(
            $typeDef->uniqueFields(),
            static fn (string $field): bool => ($extraRules[$field] ?? null) !== [],
            ARRAY_FILTER_USE_KEY,
        );
        foreach ($this->uniqueChecks($typeDef, $record->toArray(), $record->getId(), $unique) as $key => [$rule, $value]) {
            $rules[$key] = [...($rules[$key] ?? []), $rule];
            $data[$key] = $value;
        }

        if ($rules === []) {
            return;
        }

        $validator = $this->validatorFactory->make($rules, $data, subject: $subject ?? $record);
        $result = $validator->validate();

        if ($result->fails()) {
            throw new ValidationException($result);
        }
    }

    /**
     * The Unique rule of each of $fields with the value it checks, by error key: the field, or
     * "field.locale" for a translatable field's value in another locale than the default. The
     * scope is given as the record's values.
     *
     * @param array<string, mixed> $data the record's values
     * @param array<string, list<string>> $fields unique fields => scope fields
     * @return array<string, array{Unique, mixed}>
     */
    private function uniqueChecks(TypeDefinition $def, array $data, string|int|null $id, array $fields): array
    {
        $checks = [];
        $translatable = $def->translatableFields();
        foreach ($fields as $field => $scope) {
            $values = [];
            foreach ($scope as $name) {
                $values[$name] = $data[$name] ?? null;
            }
            if (!in_array($field, $translatable, true)) {
                $checks[$field] = [new Unique($this, $def, $field, $values, $id), $data[$field] ?? null];
                continue;
            }
            foreach (LocaleValue::map($data[$field] ?? null, $def->locales) as $locale => $value) {
                $locale = (string) $locale;
                if ($def->locales->has($locale)) {
                    $key = $locale === $def->locales->default ? $field : $field . '.' . $locale;
                    $checks[$key] = [new Unique($this, $def, $field, $values, $id, $locale), $value];
                }
            }
        }

        return $checks;
    }

    /**
     * The keys of the checks whose value another record has.
     *
     * @param array<string, array{Unique, mixed}> $checks
     * @return list<string>
     */
    private function takenFields(array $checks): array
    {
        $taken = [];
        foreach ($checks as $key => [$rule, $value]) {
            if ($rule->validate($value, new ValidationContext($key, [])) !== true) {
                $taken[] = $key;
            }
        }

        return $taken;
    }

    /**
     * validation.unique on each of the fields, in the validator's language when there is one.
     *
     * @param non-empty-list<string> $fields
     */
    private function uniqueFailure(array $fields): ValidationException
    {
        return $this->failure(array_fill_keys($fields, new Message('validation.unique')));
    }

    /**
     * A ValidationException with one message per field, in the validator's language when there
     * is one.
     *
     * @param non-empty-array<string, Message> $messages
     */
    private function failure(array $messages): ValidationException
    {
        if ($this->validatorFactory === null) {
            return new ValidationException(new ValidationResult(array_map(static fn (Message $m): array => [$m->english()], $messages)));
        }
        $rules = [];
        foreach ($messages as $field => $message) {
            $rules[$field] = [new class ($message) implements ValidationRule {
                public function __construct(private readonly Message $message) {}

                public function validate(mixed $value, ValidationContext $context): Message
                {
                    return $this->message;
                }
            }];
        }

        return new ValidationException($this->validatorFactory->make($rules, [])->validate());
    }

    /**
     * Runs the write of $record with its unique fields guarded: unique values no index guards
     * (translatable fields on a storage with UniqueIndexes, every unique field on other storages)
     * are checked with the write, under the type's lock where the storage has one (ExclusiveWrites;
     * without it the check and the write are not atomic); a duplicate the unique index refuses
     * becomes the field error.
     * Under the lock, a field the save leaves as stored (with its scope) is not checked again:
     * a duplicate from before the field was unique does not block other changes to the record.
     *
     * @template T
     * @param \Closure(): T $write
     * @return T
     */
    private function guarded(DynamicRecord $record, \Closure $write): mixed
    {
        $def = $record->getType();
        $unique = $def->uniqueFields();
        if ($unique === []) {
            return $write();
        }
        $driver = $this->resolveDriver($def->storage);
        $checked = $driver instanceof UniqueIndexes ? array_intersect_key($unique, array_flip($def->translatableFields())) : $unique;

        return $this->guardedWrite($driver, $def->table, $unique, $checked === [] ? null : function () use ($def, $driver, $record, $checked): array {
            $data = $record->toArray();
            $id = $record->getId();
            $stored = $id === null ? null : $driver->findOne($def->table, $id, $def->idField);
            if ($stored !== null) {
                $checked = array_filter($checked, static function (array $scope, string $field) use ($def, $data, $stored): bool {
                    foreach ([$field, ...$scope] as $name) {
                        $translatable = in_array($name, $def->translatableFields(), true);
                        $before = $translatable ? LocaleValue::map($stored[$name] ?? null, $def->locales) : $stored[$name] ?? null;
                        $after = $translatable ? LocaleValue::map($data[$name] ?? null, $def->locales) : $data[$name] ?? null;
                        if (is_scalar($before) && is_scalar($after) ? (string) $before !== (string) $after : $before != $after) {
                            return true;
                        }
                    }

                    return false;
                }, ARRAY_FILTER_USE_BOTH);
            }

            return $this->takenFields($this->uniqueChecks($def, $data, $id, $checked));
        }, $write);
    }

    /**
     * The model's write, guarded as guarded() does for records (by the index alone on a storage
     * with UniqueIndexes).
     *
     * @param array<string, mixed> $data
     * @param \Closure(): void $write
     */
    private function guardedModel(ModelMetadata $meta, array $data, \Closure $write): void
    {
        $unique = $meta->uniqueFields();
        if ($unique === []) {
            $write();

            return;
        }
        $driver = $this->resolveDriver($meta->storage);
        $id = $data[$meta->idField] ?? null;
        $id = $id === '' || $id === 0 || $id === '0' ? null : $id;
        $check = $driver instanceof UniqueIndexes ? null : function () use ($meta, $unique, $data, $id): array {
            $checks = [];
            foreach ($unique as $field => $scope) {
                $checks[$field] = [new Unique($this, $meta->modelClass, $field, array_intersect_key($data, array_flip($scope)) + array_fill_keys($scope, null), $id), $data[$field] ?? null];
            }

            return $this->takenFields($checks);
        };
        $this->guardedWrite($driver, $meta->table, $unique, $check, $write);
    }

    /**
     * @param array<string, list<string>> $unique
     * @param (\Closure(): list<string>)|null $check the taken fields, run with the write (under the
     *        type's lock on a storage with ExclusiveWrites; without one, just before it)
     */
    private function guardedWrite(StorageDriver $driver, string $table, array $unique, ?\Closure $check, \Closure $write): mixed
    {
        try {
            if ($check === null) {
                return $write();
            }
            $checked = function () use ($check, $write): mixed {
                $taken = $check();
                if ($taken !== []) {
                    throw $this->uniqueFailure($taken);
                }

                return $write();
            };

            return $driver instanceof ExclusiveWrites ? $driver->exclusive($table, $checked) : $checked();
        } catch (UniqueViolation $e) {
            $field = $e->field($table, $unique);
            if ($field === null) {
                throw $e;
            }
            throw $this->uniqueFailure([$field]);
        }
    }

    /**
     * The model's values as written: an empty unique value is NULL (never a duplicate) where its
     * property takes null back.
     *
     * @return array<string, mixed>
     */
    private function modelData(ModelMetadata $meta, Model $model): array
    {
        $data = $model->toArray();
        foreach (array_keys($meta->uniqueFields()) as $field) {
            if (($data[$field] ?? null) === '' && (new \ReflectionProperty($model, $field))->getType()?->allowsNull() !== false) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * Translatable fields are validated per locale: field "f" gets the default
     * locale's value with all rules; every other locale with a value is
     * validated as "f.<locale>" with the same rules minus "required".
     *
     * @param array<string, list<mixed>> $rules
     * @param array<string, mixed>       $data
     * @return array{0: array<string, list<mixed>>, 1: array<string, mixed>}
     */
    private function localizedValidation(TypeDefinition $typeDef, array $rules, array $data): array
    {
        $locales = $typeDef->locales;

        foreach ($typeDef->translatableFields() as $field) {
            $map = LocaleValue::map($data[$field] ?? null, $locales);
            $data[$field] = $map[$locales->default] ?? null;

            $fieldRules = $rules[$field] ?? [];
            $optional = array_values(array_filter($fieldRules, static fn ($rule) => $rule !== 'required'));
            if ($optional === []) {
                continue;
            }

            foreach ($map as $locale => $value) {
                if ($locale === $locales->default || $value === null || $value === '' || !$locales->has((string) $locale)) {
                    continue;
                }
                $data[$field . '.' . $locale] = $value;
                $rules[$field . '.' . $locale] = $optional;
            }
        }

        return [$rules, $data];
    }

    private function requireTypeRegistry(): TypeRegistry
    {
        if ($this->typeRegistry === null) {
            throw new \RuntimeException('TypeRegistry is not configured. Set data.models_path in config.');
        }
        return $this->typeRegistry;
    }

    /** @return list<string> the configured driver names ('default' included when it resolves) */
    public function driverNames(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * Whether the definition's table is there, as its storage says (DescribesColumns: the SQL
     * table, the JSON driver's directory of the type, made by its first write). A storage that
     * cannot say has nothing to make first: true.
     */
    public function hasTable(TypeDefinition $def): bool
    {
        $driver = $this->resolveDriver($def->storage);

        return !$driver instanceof DescribesColumns || $driver->hasTable($def->table);
    }

    /** The storage driver for a configured name; an unknown name is an error, never a fallback. */
    public function driver(string $name): StorageDriver
    {
        return $this->resolveDriver($name);
    }

    /** @var array<int, StorageDriver> drivers built from closures, by the closure's object id */
    private array $built = [];

    private function resolveDriver(string $name): StorageDriver
    {
        if (isset($this->drivers[$name])) {
            $driver = $this->drivers[$name];
            if ($driver instanceof \Closure) {
                $driver = $this->built[spl_object_id($driver)] ??= $driver();
                if (!$driver instanceof StorageDriver) {
                    throw new \RuntimeException("Storage driver [{$name}] is no StorageDriver.");
                }
            }

            return $driver;
        }

        throw new \RuntimeException("Storage driver [{$name}] is not configured (config/data.php drivers).");
    }
}
