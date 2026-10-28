<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Schema\ColumnKind;

/**
 * @template T of Model
 */
final class QueryBuilder
{
    private Query $query;
    private ?TypeDefinition $typeDef = null;

    /**
     * Most target ids one relation contributes to one search word. A word that matches more
     * records of the related type is too unspecific for that relation: the relation does not
     * count for that word (rather than an arbitrary subset of its matches).
     */
    public const RELATION_MATCH_LIMIT = 1000;

    /**
     * Most related ids all loose searches of one query bind together. A relation whose matches
     * would go over it does not count for that word, as above. Keeps a query's bindings (up to
     * two per id on MySQL) well under the drivers' limits (SQLite 32766, MySQL 65535).
     */
    public const RELATION_ID_BUDGET = 10000;

    /** Words of one loose search beyond this many are ignored (each binds once per text field). */
    public const MAX_LOOSE_WORDS = 32;

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
     * Repeated words count once, words after the first MAX_LOOSE_WORDS are ignored. A word
     * matching more than RELATION_MATCH_LIMIT records of a related type does not match through
     * that relation (nor once the query's RELATION_ID_BUDGET is spent).
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
        $this->looseSearches[] = ['words' => array_slice(array_values(array_unique($words)), 0, self::MAX_LOOSE_WORDS), 'fields' => $fields === null ? null : array_values($fields)];

        return $this;
    }

    /** @return array{text: list<string>, relations: list<Relation>} */
    private function looseFields(?array $requested): array
    {
        $kinds = $this->dataManager?->fieldKinds() ?? new FieldKinds();
        $text = [];
        $relations = [];

        if ($this->typeDef !== null) {
            // From the definition being queried, not the registry: a code-defined type (the media
            // library's) is in no registry.
            $relationFields = [];
            if ($this->dataManager !== null) {
                foreach ($this->typeDef->fields as $def) {
                    $relation = Relation::fromField($this->typeDef->key, $def, $kinds);
                    if ($relation !== null) {
                        $relationFields[$relation->field] = $relation;
                    }
                }
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

        $budget = self::RELATION_ID_BUDGET;
        foreach ($searches as $search) {
            $fields = $this->looseFields($search['fields']);
            foreach ($search['words'] as $word) {
                $conditions = [];
                foreach ($fields['text'] as $name) {
                    $conditions[] = ['field' => $name, 'operator' => 'CONTAINS', 'value' => $word];
                }
                foreach ($fields['relations'] as $relation) {
                    $ids = $this->relatedIds($relation, $word);
                    if ($ids === null || count($ids) > $budget) {
                        continue; // too many matches: this relation does not count for this word
                    }
                    $budget -= count($ids);
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

    /** @return list<string>|null ids of target records the word matches; null: more than RELATION_MATCH_LIMIT */
    private function relatedIds(Relation $relation, string $word): ?array
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

        $builder = $dm->queryIn($target)->whereAny($conditions)->limit(self::RELATION_MATCH_LIMIT + 1);
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

        return count($ids) > self::RELATION_MATCH_LIMIT ? null : $ids;
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
        $table = $this->meta?->table ?? $this->typeDef?->table;
        $result = $this->driver->findMany($table, $this->prepared());

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
     * Group and count, sum, average… the records this builder's filters select (where,
     * whereAny, search, searchLoose, in its locale). This builder's orderBy, limit and offset
     * do not apply: the aggregate query has its own.
     */
    public function aggregate(): AggregateQuery
    {
        $kinds = $this->dataManager?->fieldKinds() ?? new FieldKinds();
        $fields = [];
        $numeric = [];
        $multiple = [];
        if ($this->typeDef !== null) {
            $fields[] = $this->typeDef->idField;
            foreach ($this->typeDef->fields as $name => $def) {
                $fields[] = $name;
                if ($kinds->columnKindOf($def) === ColumnKind::Number) {
                    $numeric[] = $name;
                }
                if ($kinds->isRelation($def->type) && ($def->config['multiple'] ?? false)) {
                    $multiple[] = $name;
                }
            }
        } elseif ($this->meta !== null) {
            $fields = [$this->meta->idField, ...array_keys($this->meta->fields)];
            foreach ($fields as $name) {
                if (!property_exists($this->meta->modelClass, $name)) {
                    continue; // the default id field of a model without an #[Id] property
                }
                $type = (new \ReflectionProperty($this->meta->modelClass, $name))->getType();
                if ($type instanceof \ReflectionNamedType && in_array($type->getName(), ['int', 'float'], true)) {
                    $numeric[] = $name;
                }
            }
        }
        $fields = array_values(array_unique($fields));
        $numeric = array_values(array_unique($numeric));

        return new AggregateQuery(
            $this->driver,
            (string) ($this->meta?->table ?? $this->typeDef?->table),
            $this->prepared(),
            $fields,
            $numeric,
            $multiple,
        );
    }

    /** The query with the loose searches resolved and translatable fields compared in the locale. */
    private function prepared(): Query
    {
        $this->applyLooseSearches();
        $translatable = $this->typeDef?->translatableFields() ?? [];
        if ($translatable !== []) {
            $default = $this->typeDef->locales->default;
            $this->query->localize($translatable, $this->locale ?? $default, $default);
        }

        return $this->query;
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
