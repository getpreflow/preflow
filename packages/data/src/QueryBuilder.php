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

    /** Most target ids one relation contributes to one search word. */
    public const RELATION_MATCH_LIMIT = 1000;

    /** @var list<array{words: list<string>, fields: ?list<string>}> resolved at get() */
    private array $looseSearches = [];

    /**
     * @param class-string<T>|null $modelClass
     */
    public function __construct(
        private readonly StorageDriver $driver,
        private readonly ?ModelMetadata $meta,
        private readonly ?string $modelClass,
        private readonly ?DataManager $dataManager = null,
    ) {
        $this->query = new Query();
    }

    /**
     * Create a QueryBuilder for a dynamic type (no typed model class).
     */
    public static function forType(StorageDriver $driver, TypeDefinition $typeDef, ?DataManager $dm = null): self
    {
        $builder = new self($driver, null, null, $dm);
        $builder->typeDef = $typeDef;
        return $builder;
    }

    private ?string $locale = null;

    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        $this->query->where($field, $operator, $value);
        return $this;
    }

    /** @param list<array{field: string, operator: string, value: mixed}> $conditions OR'd; see Query::whereAny() */
    public function whereAny(array $conditions): self
    {
        $this->query->whereAny($conditions);
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
     * Loose search: the term is split on whitespace, every word must match
     * (AND), a word matches as a case-insensitive substring of any text field
     * or, one level deep, of the label or text fields of a related record.
     *
     * Only fields the type (or model) really has are used. Fields that say
     * "searchable": false are never searched, and neither are hidden fields
     * ("hidden": true in their config) unless they say "searchable": true —
     * also when $fields names them, and also as the label or text fields of a
     * related type. Relations need a DataManager and are resolved when get()
     * runs, so locale() applies.
     *
     * @param list<string>|null $fields restrict to these fields; unknown, hidden and
     *        "searchable": false names are dropped
     */
    public function searchLoose(string $term, ?array $fields = null): self
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return $this;
        }
        $this->looseSearches[] = ['words' => array_values($words), 'fields' => $fields === null ? null : array_values($fields)];

        return $this;
    }

    /** @return array{text: list<string>, relations: list<Relation>} */
    private function looseFields(?array $requested): array
    {
        $kinds = $this->dataManager?->fieldKinds() ?? new FieldKinds();
        $text = [];
        $relations = [];

        if ($this->typeDef !== null) {
            $relationFields = [];
            foreach ($this->dataManager?->relationsOf($this->typeDef->key) ?? [] as $relation) {
                $relationFields[$relation->field] = $relation;
            }
            foreach ($this->typeDef->fields as $name => $def) {
                if (!self::looselySearchable($def) || ($requested !== null && !in_array($name, $requested, true))) {
                    continue;
                }
                if (isset($relationFields[$name])) {
                    $relations[] = $relationFields[$name];
                } elseif ($kinds->isText($def->type)) {
                    $text[] = $name;
                }
            }
        } elseif ($this->meta !== null) {
            foreach ($this->meta->fields as $name => $attr) {
                if ($attr->searchable === false || ($requested !== null && !in_array($name, $requested, true))) {
                    continue;
                }
                $type = (new \ReflectionProperty($this->meta->modelClass, $name))->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                    $text[] = $name;
                }
            }
        }

        return ['text' => $text, 'relations' => $relations];
    }

    /**
     * Whether loose search may look at a type field: not when it says "searchable": false,
     * and not when it is hidden unless it says "searchable": true.
     */
    private static function looselySearchable(TypeFieldDefinition $def): bool
    {
        if ($def->searchable !== null) {
            return $def->searchable;
        }

        return ($def->config['hidden'] ?? false) !== true;
    }

    private function applyLooseSearches(): void
    {
        $searches = $this->looseSearches;
        $this->looseSearches = [];

        foreach ($searches as $search) {
            $fields = $this->looseFields($search['fields']);
            foreach ($search['words'] as $word) {
                $conditions = [];
                foreach ($fields['text'] as $name) {
                    $conditions[] = ['field' => $name, 'operator' => 'CONTAINS', 'value' => $word];
                }
                foreach ($fields['relations'] as $relation) {
                    $ids = $this->relatedIds($relation, $word);
                    $conditions[] = [
                        'field' => $relation->field,
                        'operator' => $relation->multiple ? 'HAS' : 'IN',
                        'value' => $ids,
                    ];
                }
                if ($conditions === []) {
                    // nothing searchable: no record can match
                    $this->query->whereAny([['field' => $this->typeDef?->idField ?? $this->meta?->idField ?? 'uuid', 'operator' => 'IN', 'value' => []]]);
                    continue;
                }
                $this->query->whereAny($conditions);
            }
        }
    }

    /** @return list<string> ids of target records the word matches */
    private function relatedIds(Relation $relation, string $word): array
    {
        $dm = $this->dataManager;
        $registry = $dm?->getTypeRegistry();
        if ($dm === null || $registry === null || !$registry->has($relation->target)) {
            return [];
        }
        $target = $registry->get($relation->target);
        $kinds = $dm->fieldKinds();

        // The label field (declared, else the first string field) and the text fields; hidden
        // ones only where they say "searchable": true.
        $names = [];
        $label = $relation->labelField !== null ? ($target->fields[$relation->labelField] ?? null) : null;
        if ($label !== null) {
            if ($label->searchable === true || ($label->config['hidden'] ?? false) !== true) {
                $names[] = $label->name;
            }
        } else {
            foreach ($target->fields as $name => $def) {
                if ($def->type === 'string' && self::looselySearchable($def)) {
                    $names[] = $name;
                    break;
                }
            }
        }
        foreach ($target->fields as $name => $def) {
            if (self::looselySearchable($def) && $kinds->isText($def->type)) {
                $names[] = $name;
            }
        }
        $conditions = array_map(
            static fn (string $name) => ['field' => $name, 'operator' => 'CONTAINS', 'value' => $word],
            array_values(array_unique($names)),
        );
        if ($conditions === []) {
            return [];
        }

        $builder = $dm->queryIn($target)->whereAny($conditions)->limit(self::RELATION_MATCH_LIMIT);
        if ($this->locale !== null) {
            $builder->locale($this->locale);
        }
        $ids = [];
        foreach ($builder->get()->items() as $record) {
            $id = $record->getId();
            if ($id !== null) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

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

    /**
     * Execute the query and return a ResultSet of model instances.
     *
     * @return ResultSet<T>
     */
    public function get(): ResultSet
    {
        $this->applyLooseSearches();
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
