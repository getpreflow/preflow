<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Validation\ValidatorFactory;
use Preflow\Validation\ValidationException;

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

        if ($data === null) {
            return null;
        }

        $model = new $modelClass();
        $model->fill($data);

        return $model;
    }

    /**
     * Start a query for a typed model.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return QueryBuilder<T>
     */
    public function query(string $modelClass): QueryBuilder
    {
        $meta = ModelMetadata::for($modelClass);
        $driver = $this->resolveDriver($meta->storage);

        return new QueryBuilder($driver, $meta, $modelClass, $this);
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
        return $driver->rawQuery($sql, $bindings);
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

        return $driver->execute($sql, $bindings);
    }

    /**
     * Save a model.
     */
    public function save(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
    {
        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $meta = ModelMetadata::for($model::class);
        $driver = $this->resolveDriver($meta->storage);
        $data = $model->toArray();
        $id = $data[$meta->idField] ?? null;
        $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

        $driver->save($meta->table, $id ?? '', $data, $meta->idField);

        if ($isEmpty) {
            $newId = $driver->lastInsertId();
            if ($newId !== '' && $newId !== 0) {
                $model->{$meta->idField} = is_int($newId) ? $newId : (is_numeric($newId) ? (int) $newId : $newId);
            }
        }
    }

    /**
     * Insert a new model. Sets the auto-generated ID on the model.
     * Use this when you explicitly want INSERT behavior (not upsert).
     */
    public function insert(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
    {
        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $meta = ModelMetadata::for($model::class);
        $driver = $this->resolveDriver($meta->storage);
        $data = $model->toArray();

        // Force INSERT by passing empty ID (PdoDriver strips the ID field and does plain INSERT)
        $driver->save($meta->table, 0, $data, $meta->idField);

        $newId = $driver->lastInsertId();
        if ($newId !== '' && $newId !== 0) {
            $model->{$meta->idField} = is_int($newId) ? $newId : (is_numeric($newId) ? (int) $newId : $newId);
        }
    }

    /**
     * Update an existing model. Throws if ID is empty.
     * Use this when you explicitly want UPDATE behavior (not upsert).
     */
    public function update(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
    {
        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $meta = ModelMetadata::for($model::class);
        $driver = $this->resolveDriver($meta->storage);
        $data = $model->toArray();
        $id = $data[$meta->idField] ?? null;

        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            throw new \RuntimeException("Cannot update model without an ID. Use insert() for new records.");
        }

        $driver->save($meta->table, $id, $data, $meta->idField);
    }

    /**
     * Delete a model by class+ID or by model instance.
     *
     * @param class-string<Model>|Model $modelClassOrInstance
     */
    public function delete(string|Model $modelClassOrInstance, string|int|null $id = null): void
    {
        if ($modelClassOrInstance instanceof Model) {
            $meta = ModelMetadata::for($modelClassOrInstance::class);
            $driver = $this->resolveDriver($meta->storage);
            $data = $modelClassOrInstance->toArray();
            $modelId = $data[$meta->idField] ?? throw new \RuntimeException("Model missing ID field [{$meta->idField}].");
            $driver->delete($meta->table, $modelId, $meta->idField);
            return;
        }

        // Original path: class + ID
        if ($id === null) {
            throw new \RuntimeException('ID is required when deleting by class name.');
        }
        $meta = ModelMetadata::for($modelClassOrInstance);
        $driver = $this->resolveDriver($meta->storage);
        $driver->delete($meta->table, $id, $meta->idField);
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

        $driver->save($typeDef->table, $id, $record->toArray(), $typeDef->idField);
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

        if ($driver instanceof VersionedStorage) {
            $written = $driver->saveIfVersion($typeDef->table, $id, $record->toArray(), $versionField, $expected, $typeDef->idField);
        } else {
            self::noticeNotVersioned($driver);
            $stored = $driver->findOne($typeDef->table, $id, $typeDef->idField);
            $written = $stored === null ? $expected === 0 : (int) ($stored[$versionField] ?? 0) === $expected;
            if ($written) {
                $driver->save($typeDef->table, $id, $record->toArray(), $typeDef->idField);
            }
        }

        if (!$written) {
            $had ? $record->set($versionField, $before) : $record->remove($versionField);
        }

        return $written;
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

        if ($rules === []) {
            return;
        }

        $validator = $this->validatorFactory->make($rules, $model->toArray(), subject: $model);
        $result = $validator->validate();

        if ($result->fails()) {
            throw new ValidationException($result);
        }
    }

    private function validateDynamicRecord(DynamicRecord $record, array $extraRules = []): void
    {
        if ($this->validatorFactory === null) {
            return;
        }

        $typeDef = $record->getType();
        $rules = $typeDef->validationRules();

        if ($extraRules !== []) {
            $rules = array_merge($rules, $extraRules);
        }

        if ($rules === []) {
            return;
        }

        [$rules, $data] = $this->localizedValidation($typeDef, $rules, $record->toArray());

        $validator = $this->validatorFactory->make($rules, $data, subject: $record);
        $result = $validator->validate();

        if ($result->fails()) {
            throw new ValidationException($result);
        }
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
