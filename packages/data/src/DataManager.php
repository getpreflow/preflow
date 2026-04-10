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
    ) {}

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
