<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * @template T of Model
 */
final class QueryBuilder
{
    private Query $query;

    /**
     * @param class-string<T> $modelClass
     */
    public function __construct(
        private readonly StorageDriver $driver,
        private readonly ModelMetadata $meta,
        private readonly string $modelClass,
    ) {
        $this->query = new Query();
    }

    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        $this->query->where($field, $operator, $value);
        return $this;
    }

    public function orWhere(string $field, mixed $operator, mixed $value = null): self
    {
        $this->query->orWhere($field, $operator, $value);
        return $this;
    }

    public function orderBy(string $field, SortDirection $direction = SortDirection::Asc): self
    {
        $this->query->orderBy($field, $direction);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query->limit($limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->query->offset($offset);
        return $this;
    }

    public function search(string $term, ?array $fields = null): self
    {
        $fields ??= $this->meta->searchableFields;
        $this->query->search($term, $fields);
        return $this;
    }

    /**
     * Execute the query and return a ResultSet of model instances.
     *
     * @return ResultSet<T>
     */
    public function get(): ResultSet
    {
        $result = $this->driver->findMany($this->meta->table, $this->query);

        $models = array_map(function (array $data) {
            $model = new ($this->modelClass)();
            $model->fill($data);
            return $model;
        }, $result->items());

        return new ResultSet($models, $result->total());
    }

    /**
     * Get the first result or null.
     *
     * @return T|null
     */
    public function first(): ?Model
    {
        $this->query->limit(1);
        $result = $this->get();

        return $result->first();
    }

    /**
     * Get a paginated result.
     *
     * @return PaginatedResult
     */
    public function paginate(int $perPage, int $currentPage = 1): PaginatedResult
    {
        $this->query->limit($perPage)->offset(($currentPage - 1) * $perPage);
        $result = $this->get();

        return $result->paginate($perPage, $currentPage);
    }
}
