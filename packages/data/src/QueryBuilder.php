<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\TextColumns;
use Preflow\Data\Search\LooseFields;

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

    /** @var list<array{words: list<string>, term: string, fields: ?list<string>}> resolved at get() */
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

    /**
     * A typed query that is its class's type (DataManager::query(), queryAs()): the type's
     * definition decides localization and loose search as for queryType(), the rows become
     * objects of the class through $hydrate, read from $table (default: the class's own).
     *
     * @param \Closure(array<string, mixed>): Model $hydrate
     * @internal built by the DataManager
     */
    public static function forModelType(
        StorageDriver $driver,
        ModelMetadata $meta,
        ?TypeDefinition $typeDef,
        DataManager $dm,
        \Closure $hydrate,
        ?string $table = null,
    ): self {
        $builder = new self($driver, $meta, $meta->modelClass, $dm);
        $builder->typeDef = $typeDef;
        $builder->hydrate = $hydrate;
        $builder->table = $table;

        return $builder;
    }

    /** @var (\Closure(array<string, mixed>): Model)|null */
    private ?\Closure $hydrate = null;

    /** Another table than the model's or type's own (queryAs()). */
    private ?string $table = null;

    private function table(): ?string
    {
        return $this->table ?? $this->meta?->table ?? $this->typeDef?->table;
    }

    private ?string $locale = null;

    /** Filters added through the public methods (where, whereAny, orWhere, search, searchLoose). */
    private int $filters = 0;

    /** @var list<string>|null the ids of byIds(), while it is the only filter */
    private ?array $byIds = null;

    private bool $unreported = false;

    /**
     * Runs of this query (get(), first(), paginate(), aggregate()) are not reported to the
     * DataManager's observer: for a lookup whose caller reports what it really depends on
     * instead (Folio finds a page's record by its address, and reports the record and the
     * address, not a read of the whole table).
     */
    public function unreported(): self
    {
        $this->unreported = true;

        return $this;
    }

    /**
     * Only the records with these ids (WHERE id IN (...)). Reported to the DataManager's observer
     * as one record read per id, found or not, instead of a read of the whole table: a page that
     * shows these records then depends on them alone (the page cache), not on every write to the
     * table. Another filter added to the query makes it a listing again.
     *
     * @param iterable<string|int> $ids
     */
    public function byIds(iterable $ids): self
    {
        $list = [];
        foreach ($ids as $id) {
            $list[] = (string) $id;
        }
        $idField = $this->meta?->idField ?? $this->typeDef?->idField ?? 'uuid';
        $this->whereAny([['field' => $idField, 'operator' => 'IN', 'value' => $list]]);
        $this->byIds = $list;

        return $this;
    }

    /**
     * See Query::where(): where($field, $value) compares with "=".
     *
     * @throws \InvalidArgumentException when the type or model class has no such field (since 0.78)
     */
    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        $this->assertField($field);
        $this->filters++;
        func_num_args() === 2 ? $this->query->where($field, $operator) : $this->query->where($field, $operator, $value);
        return $this;
    }

    /** @param list<array{field: string, operator: string, value: mixed}> $conditions OR'd; see Query::whereAny() */
    public function whereAny(array $conditions): self
    {
        foreach ($conditions as $condition) {
            $this->assertField((string) ($condition['field'] ?? ''));
        }
        $this->filters++;
        $this->query->whereAny($conditions);
        return $this;
    }

    public function orWhere(string $field, mixed $operator, mixed $value = null): self
    {
        $this->assertField($field);
        $this->filters++;
        func_num_args() === 2 ? $this->query->orWhere($field, $operator) : $this->query->orWhere($field, $operator, $value);
        return $this;
    }

    /**
     * Only the records whose $field is one of $values: a list, or the $column of the records
     * another query selects ($column defaults to $field; the subquery's own filters, order and
     * limit apply). Values are compared as stored (a transformer's fromStorage does not run). A
     * null among the values is dropped: it matches nothing in SQL, and so nowhere. An empty list
     * or subquery matches nothing.
     *
     * A list is bound as it is, so a very long one meets the database's limit on bindings. A
     * subquery on the same SQL driver (SelectsSubqueries) is compiled into this query as
     * `IN (SELECT ...)`: no values travel through PHP, no limit on bindings; the inner records are
     * then read when this query runs. Any other subquery (another driver, JSON storage, a limit or
     * offset on it) is run now, at the call, and its stored values passed as a list. The conditions
     * are fixed at the call either way, but a write in between can give different rows. Both ways
     * give the same rows otherwise.
     *
     * @param QueryBuilder<*>|list<mixed> $values
     * @throws \InvalidArgumentException for a column given with a list, or a name that is no field
     * @since 0.79.0
     */
    public function whereIn(string $field, QueryBuilder|array $values, ?string $column = null): self
    {
        return $this->whereList($field, 'IN', $values, $column);
    }

    /** As whereIn(), the records whose $field is none of $values (an empty list matches every record). @since 0.79.0 */
    public function whereNotIn(string $field, QueryBuilder|array $values, ?string $column = null): self
    {
        return $this->whereList($field, 'NOT IN', $values, $column);
    }

    /** @param QueryBuilder<*>|list<mixed> $values */
    private function whereList(string $field, string $operator, QueryBuilder|array $values, ?string $column): self
    {
        if ($values instanceof self) {
            $column ??= $field;
            if ($this->sharesSqlWith($values)) {
                // The database runs the subquery: no values travel through PHP.
                $values->assertField($column);
                $inner = $values->prepared();
                $this->assertField($field);
                $this->filters++;
                $this->query->whereInSubselect($field, $operator, new Subselect((string) $values->table(), $column, clone $inner));
                $values->reportListing();

                return $this;
            }
            $values = $values->values($column, true);
        } elseif ($column !== null) {
            throw new \InvalidArgumentException('A column names a field of a subquery; a list has none.');
        }

        return $this->where($field, $operator, array_values(array_filter($values, static fn (mixed $value): bool => $value !== null)));
    }

    /**
     * One field of each record this query selects, in the query's order and within its limit
     * and offset: a list of the values, as the records read them. Median, for instance: order by
     * the field, then take the middle of the list.
     *
     * @return list<mixed>
     * @throws \InvalidArgumentException when the type or model class has no such field
     * @since 0.79.0
     */
    public function pluck(string $field): array
    {
        return $this->values($field, false);
    }

    /**
     * Whether $inner can run inside this query as SQL: one driver instance that implements
     * SelectsSubqueries, and an inner query a database takes in IN (no limit, no offset).
     */
    private function sharesSqlWith(self $inner): bool
    {
        return $this->driver instanceof SelectsSubqueries
            && $this->driver === $inner->driver
            && $inner->table() !== null
            && $this->table() !== null
            && $inner->query->getLimit() === null
            && $inner->query->getOffset() === null;
    }

    /**
     * Whether the column's values can be what a record reads, so the database may hand them over
     * (the values are then checked by readsAsStored()): a plain record without a transformer on
     * the field, or a class field typed string (a conversion changes the other types: bool, numbers,
     * arrays) without one. Anything else reads the records.
     */
    private function mayReadColumn(string $field): bool
    {
        if ($this->typeDef !== null && $this->typeDef->translatableFields() !== []) {
            return false;
        }
        if ($this->hydrate === null && $this->modelClass === null) {
            return $this->typeDef !== null && !isset($this->typeDef->transformers[$field]);
        }
        $class = $this->meta?->modelClass ?? $this->modelClass;
        if ($class === null || isset($this->meta?->transformers[$field]) || !property_exists($class, $field)) {
            return false;
        }
        $type = (new \ReflectionProperty($class, $field))->getType();

        return $type instanceof \ReflectionNamedType && $type->getName() === 'string';
    }

    /** Whether column values, a class field's, are all taken as they are (a string; null where the type allows it). */
    private function readsAsStored(string $field, array $values): bool
    {
        if ($this->hydrate === null && $this->modelClass === null) {
            return true; // a plain record reads the column as it is
        }
        $class = $this->meta?->modelClass ?? $this->modelClass;
        $nullable = (new \ReflectionProperty((string) $class, $field))->getType()?->allowsNull() ?? true;
        foreach ($values as $value) {
            if (!is_string($value) && !($value === null && $nullable)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param bool $stored the values as storage holds them (no transformer, no conversion to the
     *        property's type): what an IN list is compared with. Else as the records read them.
     * @return list<mixed>
     */
    private function values(string $field, bool $stored): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid field name "%s": a field name has letters, digits and underscores and does not start with a digit.', substr($field, 0, 64)));
        }
        $this->assertField($field);
        $table = $this->table();
        $column = $this->driver instanceof SelectsColumns && $table !== null;
        if ($column && ($stored || $this->mayReadColumn($field))) {
            $values = $this->driver->pluck($table, $field, $this->prepared());
            if ($stored || $this->readsAsStored($field, $values)) {
                $this->reportListing();

                return $values;
            }
        }
        if ($stored) {
            $rows = $this->driver->findMany((string) $table, $this->prepared())->items();
            $this->reportListing();

            return array_map(static fn (array $row): mixed => $row[$field] ?? null, $rows);
        }
        $values = [];
        foreach ($this->get()->items() as $record) {
            $values[] = $record instanceof DynamicRecord ? $record->get($field) : ($record->{$field} ?? null);
        }

        return $values;
    }

    /** @param bool $numeric compare as numbers even where the column stores text */
    public function orderBy(string $field, SortDirection $direction = SortDirection::Asc, bool $numeric = false): self
    {
        $this->assertField($field);
        $this->query->orderBy($field, $direction, $numeric);
        return $this;
    }

    /**
     * Refuses a field the type (or plain model class) does not have, so a misspelt or removed
     * field fails the same on every driver: SQL names a missing column ("no such column"), but
     * JSON would quietly match nothing (or, for IS NULL, everything). Allowed: the fields, the id
     * field and system keys (a leading "_": versions, order, the columns adapters add); for a plain
     * model class also its other properties. A builder without a type or class checks nothing.
     */
    private function assertField(string $field): void
    {
        if ($field === '' || str_starts_with($field, '_')) {
            return;
        }
        if ($this->typeDef !== null) {
            if ($field === $this->typeDef->idField || isset($this->typeDef->fields[$field])) {
                return;
            }
            throw new \InvalidArgumentException("Unknown field \"{$field}\": type \"{$this->typeDef->key}\" has no such field to query by.");
        }
        // A plain model's columns are its migration's: a #[Field], the id, or another property of the class (#[Timestamps]).
        if ($this->meta !== null && $field !== $this->meta->idField && !isset($this->meta->fields[$field]) && !property_exists($this->meta->modelClass, $field)) {
            throw new \InvalidArgumentException("Unknown field \"{$field}\": model {$this->meta->modelClass} has no such field to query by.");
        }
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

    /**
     * Records where one of $fields (null: the type's searchable fields) holds $term, ignoring
     * case and accents (the matching rule, StorageDriver; since 0.78.1).
     *
     * @param list<string>|null $fields
     */
    public function search(string $term, ?array $fields = null): self
    {
        $this->filters++;
        $searchFields = $fields;
        if ($searchFields === null) {
            $searchFields = $this->meta?->searchableFields ?? $this->typeDef?->searchableFields ?? [];
        }
        $this->query->search($term, $searchFields);
        return $this;
    }

    /**
     * Loose search: the term is split on whitespace, every word must match
     * (AND), a word matches as a substring of any text field or, one level
     * deep, of the label or text fields of a related record, ignoring case and
     * accents (the matching rule, StorageDriver; since 0.78.1: "uber" finds "Über").
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
        $this->filters++;
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return $this;
        }
        $this->looseSearches[] = [
            'words' => array_slice(array_values(array_unique($words)), 0, self::MAX_LOOSE_WORDS),
            'term' => trim($term),
            'fields' => $fields === null ? null : array_values($fields),
        ];

        return $this;
    }

    /** @return array{text: list<string>, whole: list<string>, relations: list<Relation>} */
    private function looseFields(?array $requested): array
    {
        $kinds = $this->dataManager?->fieldKinds() ?? new FieldKinds();
        $text = [];
        $whole = [];
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
            $own = LooseFields::text($this->typeDef, $kinds);
            $exact = LooseFields::exact($this->typeDef, $kinds);
            foreach ($this->typeDef->fields as $name => $def) {
                if ($requested !== null && !in_array($name, $requested, true)) {
                    continue;
                }
                if (in_array($name, $exact, true)) {
                    $whole[] = $name;
                } elseif (!LooseFields::searchable($def)) {
                    continue;
                } elseif (isset($relationFields[$name])) {
                    $relations[] = $relationFields[$name];
                } elseif (in_array($name, $own, true)) {
                    $text[] = $name;
                }
            }
        } elseif ($this->meta !== null) {
            foreach ($this->meta->fields as $name => $attr) {
                if ($attr->searchable === false || ($requested !== null && !in_array($name, $requested, true))) {
                    continue;
                }
                $type = ModelSchema::fieldType($this->meta, (string) $name);
                if ($type !== null && $kinds->isText($type) && !$kinds->isList($type)) {
                    if ($attr->exact) {
                        $whole[] = $name; // a machine value: found by its whole value only
                    } else {
                        $text[] = $name;
                    }
                }
            }
        }

        return ['text' => $text, 'whole' => $whole, 'relations' => $relations];
    }

    private function applyLooseSearches(): void
    {
        $searches = $this->looseSearches;
        $this->looseSearches = [];

        $budget = self::RELATION_ID_BUDGET;
        foreach ($searches as $search) {
            $fields = $this->looseFields($search['fields']);
            $term = count($search['words']) > 1 ? $search['term'] : null;
            foreach ($search['words'] as $word) {
                $conditions = LooseFields::conditions($fields['text'], $fields['whole'], $word, $term);
                foreach ($fields['relations'] as $relation) {
                    $ids = $this->relatedIds($relation, $word, $term);
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
    private function relatedIds(Relation $relation, string $word, ?string $term = null): ?array
    {
        $dm = $this->dataManager;
        $registry = $dm?->getTypeRegistry();
        if ($dm === null || $registry === null || !$registry->has($relation->target)) {
            return [];
        }
        $target = $registry->get($relation->target);
        $kinds = $dm->fieldKinds();

        // The label field (the relation's, else the target's titleField, else its first string
        // field) and the text fields; hidden ones only where they say "searchable": true.
        // An exact one is compared whole (LooseFields::conditions()).
        $names = [];
        $labelField = $relation->labelField ?? $target->titleField;
        $label = $labelField !== null ? ($target->fields[$labelField] ?? null) : null;
        if ($label !== null) {
            if ($label->searchable === true || ($label->config['hidden'] ?? false) !== true) {
                $names[] = $label->name;
            }
        } else {
            foreach ($target->fields as $name => $def) {
                if ($def->type === 'string' && LooseFields::searchable($def)) {
                    $names[] = $name;
                    break;
                }
            }
        }
        foreach ($target->fields as $name => $def) {
            if (LooseFields::searchable($def) && $kinds->isText($def->type)) {
                $names[] = $name;
            }
        }
        $whole = LooseFields::exact($target, $kinds);
        $names = array_values(array_diff(array_unique($names), $whole)); // an exact label is compared whole
        if ($label !== null && $label->exact && $kinds->isText($label->type) && !in_array($label->name, $whole, true) && ($label->searchable === true || ($label->config['hidden'] ?? false) !== true)) {
            $whole[] = $label->name;
        }
        $conditions = LooseFields::conditions($names, $whole, $word, $term);
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
        $result = $this->driver->findMany($this->table(), $this->prepared());
        $this->reportListing();

        if ($this->hydrate !== null) {
            return new ResultSet(array_map($this->hydrate, $result->items()), $result->total());
        }

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
     * whereAny, search, searchLoose, in its locale), as they are now: later changes to this
     * builder do not reach the aggregate query. This builder's orderBy, limit and offset do
     * not apply: the aggregate query has its own.
     */
    public function aggregate(): AggregateQuery
    {
        $kinds = $this->dataManager?->fieldKinds() ?? new FieldKinds();
        $fields = [];
        $numeric = [];
        $multiple = [];
        $boolean = [];
        if ($this->typeDef !== null) {
            $fields[] = $this->typeDef->idField;
            foreach ($this->typeDef->fields as $name => $def) {
                $fields[] = $name;
                $kind = $kinds->columnKindOf($def);
                if ($kind === ColumnKind::Number) {
                    $numeric[] = $name;
                } elseif ($kind === ColumnKind::Boolean) {
                    $boolean[] = $name;
                }
                if ($kinds->isRelation($def->type) && $kinds->holdsList($def)) { // relation.multiple (0.89: was read from the wrong key)
                    $multiple[] = $name;
                }
            }
        } elseif ($this->meta !== null) {
            $fields = [$this->meta->idField, ...array_keys($this->meta->fields)];
            foreach ($fields as $name) {
                // The default id field of a model without an #[Id] property has no type.
                $type = ModelSchema::fieldType($this->meta, $name);
                if ($type === 'number') {
                    $numeric[] = $name;
                } elseif ($type === 'boolean') {
                    $boolean[] = $name;
                }
            }
        }
        $fields = array_values(array_unique($fields));
        $numeric = array_values(array_unique($numeric));

        return new AggregateQuery(
            $this->driver,
            (string) $this->table(),
            clone $this->prepared(),
            $fields,
            $numeric,
            $multiple,
            array_values(array_unique($boolean)),
            $this->reporter(), // reported when it runs, as the query stands now
        );
    }

    /** Tells the DataManager's observer that this type was read as a listing. */
    private function reportListing(): void
    {
        $this->reporter()();
    }

    /**
     * What a run of this query reports, decided now (byIds() while it is the only filter: its
     * ids, else a listing): an aggregate built before the builder changes keeps its own.
     *
     * @return \Closure(): void
     */
    private function reporter(): \Closure
    {
        $dm = $this->dataManager;
        $storage = $this->meta?->storage ?? $this->typeDef?->storage;
        $table = $this->table();
        if ($dm === null || $storage === null || $table === null || $this->unreported) {
            return static function (): void {};
        }
        $ids = $this->byIds !== null && $this->filters === 1 ? array_values(array_unique($this->byIds)) : null;

        return static function () use ($dm, $storage, $table, $ids): void {
            if ($ids === null) {
                $dm->reportRead($storage, $table);

                return;
            }
            foreach ($ids as $id) {
                $dm->reportRead($storage, $table, $id);
            }
        };
    }

    /**
     * The query with the loose searches resolved, translatable fields compared in the locale and
     * text fields compared by the text rule (TextColumns: a type's, or a plain model class's).
     * Which field types are text comes from the DataManager's field kinds; a builder made without
     * one (forType($driver, $def)) knows only the built-in ones (string, text), not the text
     * kinds an extension registers.
     */
    private function prepared(): Query
    {
        $this->applyLooseSearches();
        $kinds = $this->dataManager?->fieldKinds() ?? new FieldKinds();
        if ($this->typeDef === null) {
            if ($this->meta !== null) {
                $this->query->compareAsText(TextColumns::ofModel($this->meta, $kinds));
            }

            return $this->query;
        }
        $translatable = $this->typeDef->translatableFields();
        if ($translatable !== []) {
            $default = $this->typeDef->locales->default;
            $this->query->localize($translatable, $this->locale ?? $default, $default, $this->typeDef->locales->available);
        }
        $this->query->compareAsText(TextColumns::of($this->typeDef, $kinds));

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
