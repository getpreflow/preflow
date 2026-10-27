<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Driver\PdoDriver;
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
     *
     * @throws ValidationException when $validate and the model fails its rules, and (validated
     *         or not) when the storage refuses a unique field's duplicate (nothing saved)
     */
    public function save(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
    {
        if ($validate) {
            $this->validateModel($model, $rules, $scenario);
        }

        $meta = ModelMetadata::for($model::class);
        $driver = $this->resolveDriver($meta->storage);
        $data = $this->modelData($meta, $model);
        $id = $data[$meta->idField] ?? null;
        $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

        $this->guardedModel($meta, $data, fn () => $driver->save($meta->table, $id ?? '', $data, $meta->idField));

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
        $data = $this->modelData($meta, $model);

        // Force INSERT by passing empty ID (PdoDriver strips the ID field and does plain INSERT)
        $this->guardedModel($meta, $data, fn () => $driver->save($meta->table, 0, $data, $meta->idField));

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
        $data = $this->modelData($meta, $model);
        $id = $data[$meta->idField] ?? null;

        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            throw new \RuntimeException("Cannot update model without an ID. Use insert() for new records.");
        }

        $this->guardedModel($meta, $data, fn () => $driver->save($meta->table, $id, $data, $meta->idField));
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
        }

        return $written;
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

    private function validateDynamicRecord(DynamicRecord $record, array $extraRules = []): void
    {
        if ($this->validatorFactory === null) {
            return;
        }

        $typeDef = $record->getType();
        $rules = $typeDef->validationRules();

        // A unique or indexed string, and a unique field's scope, is a bounded column: it may not outgrow it.
        foreach ($this->fieldKinds()->lengthRules($typeDef) as $name => $lengthRule) {
            $rules[$name] = array_values(array_unique([...($rules[$name] ?? []), $lengthRule]));
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

        $validator = $this->validatorFactory->make($rules, $data, subject: $record);
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
