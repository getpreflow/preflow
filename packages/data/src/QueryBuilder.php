<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * @template T of Model
 */
final class QueryBuilder
{
    private Query $query;
    private ?TypeDefinition $typeDef = null;

    /**
     * @param class-string<T>|null $modelClass
     */
    public function __construct(
        private readonly StorageDriver $driver,
        private readonly ?ModelMetadata $meta,
        private readonly ?string $modelClass,
    ) {
        $this->query = new Query();
    }

    /**
     * Create a QueryBuilder for a dynamic type (no typed model class).
     */
    public static function forType(StorageDriver $driver, TypeDefinition $typeDef): self
    {
        $builder = new self($driver, null, null);
        $builder->typeDef = $typeDef;
        return $builder;
    }

    private ?string $locale = null;

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

    /** @param bool $numeric compare as numbers even where the column stores text */
    public function orderBy(string $field, SortDirection $direction = SortDirection::Asc, bool $numeric = false): self
    {
        $this->query->orderBy($field, $direction, $numeric);
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
        $searchFields = $fields;
        if ($searchFields === null) {
            $searchFields = $this->meta?->searchableFields ?? $this->typeDef?->searchableFields ?? [];
        }
        $this->query->search($term, $searchFields);
        return $this;
    }

    /**
     * Execute the query and return a ResultSet of model instances.
     *
     * @return ResultSet<T>
     */
    /**
     * Match translatable fields against their value in this locale (default:
     * the type's default locale).
     *
     * @throws \InvalidArgumentException for anything that is not a locale code
     */
    public function locale(string $locale): self
    {
        if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid locale "%s".', $locale));
        }
        $this->locale = $locale;

        return $this;
    }

    public function get(): ResultSet
    {
        $translatable = $this->typeDef?->translatableFields() ?? [];
        if ($translatable !== []) {
            $default = $this->typeDef->locales->default;
            $this->query->localize($translatable, $this->locale ?? $default, $default);
        }

        $table = $this->meta?->table ?? $this->typeDef?->table;
        $result = $this->driver->findMany($table, $this->query);

        if ($this->typeDef !== null) {
            $items = array_map(
                fn(array $row) => DynamicRecord::fromArray($this->typeDef, $row),
                $result->items(),
            );
            return new ResultSet($items, $result->total());
        }

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
    public function first(): mixed
    {
        $this->query->limit(1);
        $result = $this->get();

        return $result->first();
    }

    /**
     * Get a paginated result.
     */
    public function paginate(int $perPage, int $currentPage = 1): PaginatedResult
    {
        $this->query->limit($perPage)->offset(($currentPage - 1) * $perPage);
        $result = $this->get();

        return $result->paginate($perPage, $currentPage);
    }
}
