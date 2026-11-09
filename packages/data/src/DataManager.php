<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Observe\DataObserver;
use Preflow\Data\Observe\SqlTables;
use Preflow\Data\Schema\ColumnKind;
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
     * @param array<string, StorageDriver> $drivers Named drivers (e.g., 'sqlite' => SqliteDriver)
     */
    public function __construct(
        private readonly array $drivers,
        private readonly string $defaultDriver = 'default',
        private ?TypeRegistry $typeRegistry = null,
        private readonly ?ValidatorFactory $validatorFactory = null,
    ) {}

    private ?FieldKinds $fieldKinds = null;

    private ?DataObserver $observer = null;

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
     * Reports again reads captured by captureReads() (a memo hit).
     *
     * @param list<array<int, mixed>> $reads
     */
    public function replayReads(array $reads): void
    {
        if ($this->observer === null) {
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
        $driver = $this->resolveDriver($meta->storage);

        $data = $driver->findOne($meta->table, $id, $meta->idField);
        $this->reportRead($meta->storage, $meta->table, (string) $id);

        return $data === null ? null : $this->hydrate($meta, $this->classType($meta), $data);
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
        $data = $this->resolveDriver($meta->storage)->findOne($table, $id, $meta->idField);
        $this->reportRead($meta->storage, $table, (string) $id);

        return $data === null ? null : $this->markReadOnly($this->hydrate($meta, $this->classType($meta), $data), $table);
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
     * null for an ordinary model (or a type-marked class with no registry holding its key),
     * which keeps today's typed-model behaviour.
     */
    private function classType(ModelMetadata $meta): ?TypeDefinition
    {
        if ($meta->type === null || $this->typeRegistry === null || !$this->typeRegistry->has($meta->type)) {
            return null;
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
        $driver = $this->resolveDriver($storage);
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
     * Execute a raw SQL write (UPDATE, DELETE, an upsert) and return the
     * affected rows. Only PDO drivers run SQL; any other driver is a LogicException.
     *
     * @param array<int|string, mixed> $bindings Parameter bindings
     */
    public function execute(string $sql, array $bindings = [], string $storage = 'default'): int
    {
        $driver = $this->resolveDriver($storage);
        if (!$driver instanceof Driver\PdoDriver) {
            throw new \LogicException("Storage driver [{$storage}] does not run SQL; execute() needs a PDO driver.");
        }

        try {
            return $driver->execute($sql, $bindings);
        } finally {
            $this->observer?->rawWrote($this->storageKey($storage), $sql); // also after a failure: part may be written
        }
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
        if ($def !== null) {
            $this->writeClassType($model, $meta, $def, 'save', $validate, $rules, $scenario);

            return;
        }

        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $driver = $this->resolveDriver($meta->storage);
        $data = $this->modelData($meta, $model);
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
     * @throws \LogicException for an object loaded read-only (findAs(), queryAs())
     */
    public function insert(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
    {
        $this->assertWritable($model);
        $meta = ModelMetadata::for($model::class);
        $def = $this->classType($meta);
        if ($def !== null) {
            $this->writeClassType($model, $meta, $def, 'insert', $validate, $rules, $scenario);

            return;
        }

        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $driver = $this->resolveDriver($meta->storage);
        $data = $this->modelData($meta, $model);

        // Force INSERT by passing empty ID (PdoDriver strips the ID field and does plain INSERT)
        $this->guardedModel($meta, $data, fn () => $driver->save($meta->table, 0, $data, $meta->idField));

        $this->takeInsertId($driver, $meta, $model);
        $this->wroteModel($meta, $model);
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
        if ($def !== null) {
            $this->writeClassType($model, $meta, $def, 'update', $validate, $rules, $scenario);

            return;
        }

        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $driver = $this->resolveDriver($meta->storage);
        $data = $this->modelData($meta, $model);
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
     * @param 'save'|'insert'|'update' $mode
     */
    private function writeClassType(Model $model, ModelMetadata $meta, TypeDefinition $def, string $mode, bool $validate, array $rules, ?string $scenario): void
    {
        $record = new DynamicRecord($def, $this->classTypeValues($model, $def));
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
        if ($mode === 'insert' && $given && $driver->findOne($def->table, $id, $def->idField) !== null) {
            throw new \RuntimeException('Cannot insert ' . $model::class . " {$id}: a record with this id exists. Use save() or update().");
        }

        $data = $this->storageData($record);
        $this->guarded($record, function () use ($driver, $def, $id, $data): void {
            if (self::emptyId($id)) {
                $driver->save($def->table, 0, $data, $def->idField); // an auto-increment id: the storage gives it

                return;
            }
            $this->saveKeepingOtherColumns($driver, $def, $id, $data);
        });

        if (self::emptyId($id)) {
            $this->takeInsertId($driver, $meta, $model);
        }
        $this->wroteModel($meta, $model);
    }

    /**
     * Writes $data over the stored record, leaving the columns $data does not have as they
     * are. SQL storage does that itself; a storage that writes whole records (JSON files) gets
     * the stored record with $data laid over it, read and written under the type's lock where
     * the storage has one, so a version-checked save in between is not undone.
     *
     * @param array<string, mixed> $data
     */
    private function saveKeepingOtherColumns(StorageDriver $driver, TypeDefinition $def, string|int $id, array $data): void
    {
        if ($driver instanceof PdoDriver) {
            $driver->save($def->table, $id, $data, $def->idField);

            return;
        }
        $write = static function () use ($driver, $def, $id, $data): void {
            $stored = $driver->findOne($def->table, $id, $def->idField);
            $driver->save($def->table, $id, $stored === null ? $data : array_replace($stored, $data), $def->idField);
        };
        $driver instanceof ExclusiveWrites ? $driver->exclusive($def->table, $write) : $write();
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
        $values = [];
        foreach ([$def->idField, ...array_keys($def->fields)] as $name) {
            if (array_key_exists($name, $vars)) {
                $values[$name] = $vars[$name];
            }
        }

        return $values;
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
     * type. A NULL a property cannot take leaves its default.
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
            [$assign, $value] = self::asPropertyType($types[$name], $value);
            if ($assign) {
                $model->{$name} = $value;
            }
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
     * A stored scalar as the property's (single, built-in) type: whether to assign it, and the
     * value. Anything else is assigned as it is.
     *
     * @return array{bool, mixed}
     */
    private static function asPropertyType(?\ReflectionType $type, mixed $value): array
    {
        if ($type === null) {
            return [true, $value];
        }
        if ($value === null || ($value === '' && $type instanceof \ReflectionNamedType && in_array($type->getName(), ['int', 'float', 'bool'], true))) {
            return $type->allowsNull() ? [true, null] : [false, null];
        }
        if (!$type instanceof \ReflectionNamedType || !is_scalar($value)) {
            return [true, $value];
        }

        return [true, match (true) {
            $type->getName() === 'int' && is_numeric($value) && (int) $value == $value => (int) $value,
            $type->getName() === 'float' && is_numeric($value) => (float) $value,
            $type->getName() === 'bool' && !is_bool($value) => (bool) $value,
            $type->getName() === 'string' && !is_string($value) => (string) $value,
            default => $value,
        }];
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
     * validated or not (the unique index on SQL; translatable fields and JSON storage are checked
     * in the save under the type's lock, ExclusiveWrites). A duplicate is a ValidationException
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

        $this->guarded($record, fn () => $driver->save($typeDef->table, $id, $this->storageData($record), $typeDef->idField));
        $this->reportWrite($typeDef->storage, $typeDef->table, (string) $id);
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
     * A row of this type as it is written: an empty string in a number, yes/no or date field is
     * NULL (those columns hold no empty text), and so is an empty unique value (NULL is never a
     * duplicate in a unique index, '' would be); other empty text stays ''. For callers writing
     * rows to a driver themselves (an import), as saveType() does.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function storageValues(TypeDefinition $def, array $data): array
    {
        foreach ($def->fields as $name => $field) {
            if (($data[$name] ?? null) !== '') {
                continue;
            }
            $kind = $this->fieldKinds()->columnKindOf($field);
            if (in_array($kind, [ColumnKind::Number, ColumnKind::Boolean, ColumnKind::Date], true) || ($field->unique !== false && $kind !== ColumnKind::Text)) {
                $data[$name] = null;
            }
        }

        return $data;
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
     * (translatable fields on SQL, every unique field on other storages) are checked under the
     * type's lock, with the write; a duplicate the unique index refuses becomes the field error.
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
        $checked = $driver instanceof PdoDriver ? array_intersect_key($unique, array_flip($def->translatableFields())) : $unique;

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
     * The model's write, guarded as guarded() does for records (on SQL by the index alone).
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
        $check = $driver instanceof PdoDriver ? null : function () use ($meta, $unique, $data, $id): array {
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
     * @param (\Closure(): list<string>)|null $check the taken fields, run under the lock with the write
     */
    private function guardedWrite(StorageDriver $driver, string $table, array $unique, ?\Closure $check, \Closure $write): mixed
    {
        try {
            if ($check === null || !$driver instanceof ExclusiveWrites) {
                return $write();
            }

            return $driver->exclusive($table, function () use ($check, $write): mixed {
                $taken = $check();
                if ($taken !== []) {
                    throw $this->uniqueFailure($taken);
                }

                return $write();
            });
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

    /** The storage driver for a configured name; an unknown name is an error, never a fallback. */
    public function driver(string $name): StorageDriver
    {
        return $this->resolveDriver($name);
    }

    private function resolveDriver(string $name): StorageDriver
    {
        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        throw new \RuntimeException("Storage driver [{$name}] is not configured (config/data.php drivers).");
    }
}
