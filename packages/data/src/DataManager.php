<?php

declare(strict_types=1);

namespace Preflow\Data;

final class DataManager
{
    /**
     * @param array<string, StorageDriver> $drivers Named drivers (e.g., 'sqlite' => SqliteDriver)
     */
    public function __construct(
        private readonly array $drivers,
        private readonly string $defaultDriver = 'default',
        private readonly ?TypeRegistry $typeRegistry = null,
    ) {}

    public function getTypeRegistry(): ?TypeRegistry
    {
        return $this->typeRegistry;
    }

    /**
     * Find a single model by ID.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return T|null
     */
    public function find(string $modelClass, string $id): ?Model
    {
        $meta = ModelMetadata::for($modelClass);
        $driver = $this->resolveDriver($meta->storage);

        $data = $driver->findOne($meta->table, $id);

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

        return new QueryBuilder($driver, $meta, $modelClass);
    }

    /**
     * Save a model.
     */
    public function save(Model $model): void
    {
        $meta = ModelMetadata::for($model::class);
        $driver = $this->resolveDriver($meta->storage);
        $data = $model->toArray();
        $id = $data[$meta->idField] ?? throw new \RuntimeException("Model missing ID field [{$meta->idField}].");

        $driver->save($meta->table, $id, $data);
    }

    /**
     * Delete a model by class and ID.
     *
     * @param class-string<Model> $modelClass
     */
    public function delete(string $modelClass, string $id): void
    {
        $meta = ModelMetadata::for($modelClass);
        $driver = $this->resolveDriver($meta->storage);

        $driver->delete($meta->table, $id);
    }

    /**
     * Find a single dynamic record by type and ID.
     */
    public function findType(string $type, string $id): ?DynamicRecord
    {
        $typeDef = $this->requireTypeRegistry()->get($type);
        $driver = $this->resolveDriver($typeDef->storage);
        $data = $driver->findOne($typeDef->table, $id);

        if ($data === null) {
            return null;
        }

        return DynamicRecord::fromArray($typeDef, $data);
    }

    /**
     * Start a query for a dynamic type.
     */
    public function queryType(string $type): QueryBuilder
    {
        $typeDef = $this->requireTypeRegistry()->get($type);
        $driver = $this->resolveDriver($typeDef->storage);

        return QueryBuilder::forType($driver, $typeDef);
    }

    /**
     * Save a dynamic record.
     */
    public function saveType(DynamicRecord $record): void
    {
        $typeDef = $record->getType();
        $driver = $this->resolveDriver($typeDef->storage);
        $id = $record->getId();

        if ($id === null) {
            throw new \RuntimeException("DynamicRecord must have an ID ({$typeDef->idField}) before saving.");
        }

        $driver->save($typeDef->table, $id, $record->toArray());
    }

    /**
     * Delete a dynamic record by type and ID.
     */
    public function deleteType(string $type, string $id): void
    {
        $typeDef = $this->requireTypeRegistry()->get($type);
        $driver = $this->resolveDriver($typeDef->storage);
        $driver->delete($typeDef->table, $id);
    }

    private function requireTypeRegistry(): TypeRegistry
    {
        if ($this->typeRegistry === null) {
            throw new \RuntimeException('TypeRegistry is not configured. Set data.models_path in config.');
        }
        return $this->typeRegistry;
    }

    private function resolveDriver(string $name): StorageDriver
    {
        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        if (isset($this->drivers[$this->defaultDriver])) {
            return $this->drivers[$this->defaultDriver];
        }

        throw new \RuntimeException("Storage driver [{$name}] not configured.");
    }
}
