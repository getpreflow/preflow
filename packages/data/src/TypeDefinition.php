<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class TypeDefinition
{
    /** Whether editors and APIs may create its records (never for a read-only type). @since 0.78 */
    public bool $creatable;

    /**
     * @param array<string, TypeFieldDefinition> $fields
     * @param string[] $searchableFields
     * @param array<string, FieldTransformer> $transformers
     * @param list<list<string>> $indexes composite indexes, each the fields it covers in order
     * @param ?string $titleField the field that names a record (RecordTitle); null: the first string field
     *        that is not hidden. @since 0.77
     * @param bool $fullText the type declares full-text search: on a server with full-text indexes the table
     *        sync indexes its loosely searchable text fields (Search\LooseFields::text()). @since 0.78
     * @param bool $readonly editors and APIs may read its records, never create, change or delete them
     *        ("readonly": true; a site whose records come from files or code). @since 0.78
     * @param bool $creatable editors and APIs may create its records ("creatable": false: an inbox of
     *        what visitors sent, still changed and deleted by staff); false for a read-only type. @since 0.78
     * @param ?IdKind $idKind the id kind the type's model class states (IdKind::ofModel(); TypeRegistry
     *        sets it for a class-backed type). null: no class states one, so ask DataManager::idKind(),
     *        which answers for every type (the storage, then the declared id field). @since 0.82.0
     * @param bool $changeDates its records keep a change date (ChangeDates, `_updated_at`); false for a
     *        type whose rows are never changed after they are written (a log). @since 0.84.0
     * @throws \InvalidArgumentException an index without fields, over a field the type does not have (or twice
     *         over one), over a translatable field, or named as another index (a field's own, a unique field's,
     *         another composite's); a titleField the type does not have
     */
    public function __construct(
        public string $key,
        public string $table,
        public string $storage,
        public array $fields,
        public string $idField = 'uuid',
        public array $searchableFields = [],
        public array $transformers = [],
        public Locales $locales = new Locales(['en'], 'en'),
        public array $indexes = [],
        public ?string $titleField = null,
        public bool $fullText = false,
        public bool $readonly = false,
        bool $creatable = true,
        public ?IdKind $idKind = null,
        public bool $changeDates = true,
    ) {
        $this->creatable = $creatable && !$readonly;
        $this->assertIndexes();
        if ($titleField !== null && !isset($fields[$titleField])) {
            throw new \InvalidArgumentException("Type \"{$key}\" has the titleField \"{$titleField}\", which is not one of its fields.");
        }
    }

    /**
     * Whether an editor or an API may do this with the type's records: a read-only type allows no
     * create, update or delete, a type that is not creatable no create. Every adapter (Folio, an API)
     * asks this; code writing through DataManager is not restricted by it.
     *
     * @since 0.78
     */
    public function allows(TypeAction $action): bool
    {
        return match ($action) {
            TypeAction::Create => $this->creatable,
            TypeAction::Update, TypeAction::Delete => !$this->readonly,
        };
    }

    /**
     * allows(), as a refusal an adapter lets through: a 403 (TypeActionRefused).
     *
     * @throws TypeActionRefused
     * @since 0.78
     */
    public function assertAllows(TypeAction $action): void
    {
        if (!$this->allows($action)) {
            throw new TypeActionRefused($this->key, $action, $this->readonly);
        }
    }

    /**
     * Structural checks only; whether a key fits a server's limit is the sync's to judge
     * (RecordTableSync::indexes()). A composite is named idx_{table}_{its fields joined by _}, so it
     * may not share that name with an indexed field's index, nor with the index a unique field or a
     * scope field gets in published copies (ShadowTypes): a one-field composite over a unique field
     * is refused (its unique index serves already), one over a scope field is the same index.
     */
    private function assertIndexes(): void
    {
        $unique = array_keys($this->uniqueFields());
        $declared = [...$this->indexedFields(), ...$unique];
        $scopes = array_values(array_diff($this->scopeFields(), $declared));
        foreach ($this->indexes as $columns) {
            $over = '(' . implode(', ', $columns) . ')';
            if ($columns === []) {
                throw new \InvalidArgumentException("Type \"{$this->key}\" has an index without fields.");
            }
            foreach ($columns as $column) {
                $field = $this->fields[$column] ?? throw new \InvalidArgumentException("Type \"{$this->key}\" has an index over \"{$column}\", which is not one of its fields.");
                if ($field->translatable) {
                    throw new \InvalidArgumentException("Type \"{$this->key}\" has an index over \"{$column}\", which is translatable: free text gets no index.");
                }
            }
            if (count(array_unique($columns)) !== count($columns)) {
                throw new \InvalidArgumentException("Type \"{$this->key}\" has the index {$over} with a field twice.");
            }
            $name = implode('_', $columns);
            if (in_array($name, $unique, true)) {
                throw new \InvalidArgumentException($columns === [$name]
                    ? "Type \"{$this->key}\" has the index {$over} over its unique field \"{$name}\", whose unique index serves already: drop the index."
                    : "Type \"{$this->key}\" has the index {$over}, named like the index its unique field \"{$name}\" gets in published copies: rename a field.");
            }
            if (in_array($name, $scopes, true) && $columns !== [$name]) {
                throw new \InvalidArgumentException("Type \"{$this->key}\" has the index {$over}, named like the index its scope field \"{$name}\" gets in published copies: rename a field.");
            }
            if (in_array($name, $declared, true)) {
                throw new \InvalidArgumentException("Type \"{$this->key}\" has two indexes named idx_{$this->table}_{$name} ({$over} and another): drop one, or rename a field.");
            }
            $declared[] = $name;
        }
    }

    /**
     * Fields whose values differ per locale. Empty for single-locale apps:
     * "translatable" has no effect there and values stay plain scalars.
     *
     * @return list<string>
     */
    public function translatableFields(): array
    {
        if (!$this->locales->isMultilingual()) {
            return [];
        }

        return array_keys(array_filter($this->fields, static fn ($f) => $f->translatable));
    }

    /**
     * Unique fields with the fields that scope their uniqueness (empty: unique across the table).
     *
     * @return array<string, list<string>>
     */
    public function uniqueFields(): array
    {
        $unique = [];
        foreach ($this->fields as $name => $field) {
            if ($field->unique !== false) {
                $unique[$name] = $field->unique === true ? [] : $field->unique;
            }
        }

        return $unique;
    }

    /** @return list<string> */
    public function indexedFields(): array
    {
        return array_keys(array_filter($this->fields, static fn ($f) => $f->index));
    }

    /** @return list<string> the fields that scope a unique field's uniqueness */
    private function scopeFields(): array
    {
        $names = [];
        foreach ($this->uniqueFields() as $scope) {
            array_push($names, ...$scope);
        }

        return array_values(array_unique($names));
    }

    /**
     * Fields stored as indexed columns without an index of their own: the scope fields of unique
     * fields and the fields of composite indexes.
     *
     * @return list<string>
     */
    public function indexedByOthers(): array
    {
        $names = [];
        foreach ($this->uniqueFields() as $scope) {
            array_push($names, ...$scope);
        }
        foreach ($this->indexes as $columns) {
            array_push($names, ...$columns);
        }

        return array_values(array_unique($names));
    }

    /**
     * Collect validation rules from all field definitions.
     *
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->fields as $name => $field) {
            if ($field->validate !== []) {
                $rules[$name] = $field->validate;
            }
        }

        return $rules;
    }
}
