<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Index;
use Preflow\Data\Attributes\Relation;
use Preflow\Validation\Attributes\Validate;

/**
 * Describes a model class marked `#[Entity(type: …)]` as the schema array a model JSON
 * file holds, so a class and a file are the same thing to everything that reads types.
 */
final class ModelSchema
{
    /**
     * Keys that define a type's data; every other type key is an admin setting (label, list,
     * section, and the matrixable and views that Folio reads from the schema).
     */
    public const array SCHEMA_TYPE_KEYS = ['key', 'table', 'storage', 'id_field', 'fields', 'indexes'];

    /**
     * Keys that define a field's data; every other field key is presentation (label, help, readonly, ...).
     * "multiple" is data: it decides the column (FieldKinds stores a multiple relation as text).
     */
    public const array SCHEMA_FIELD_KEYS = [
        'type', 'validate', 'translatable', 'unique', 'index', 'length', 'searchable',
        'transform', 'default', 'relation', 'options', 'accept', 'time', 'blocks', 'fields', 'multiple',
    ];

    /**
     * @param class-string $modelClass
     * @return array<string, mixed>
     */
    public static function of(string $modelClass): array
    {
        $ref = new \ReflectionClass($modelClass);
        $entityAttrs = $ref->getAttributes(Entity::class);
        $entity = $entityAttrs === [] ? null : $entityAttrs[0]->newInstance();
        if ($entity === null || $entity->type === null) {
            throw new \LogicException(
                "Model [{$modelClass}] is not a data type: mark it with #[Entity(type: …)] to describe it as one."
            );
        }

        $idField = 'uuid';
        $fields = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getAttributes(Id::class) !== []) {
                $idField = $prop->getName();
                continue;
            }
            $fieldAttrs = $prop->getAttributes(Field::class);
            $relationAttrs = $prop->getAttributes(Relation::class);
            if ($fieldAttrs === [] && $relationAttrs === []) {
                continue;
            }
            $field = $fieldAttrs === [] ? new Field() : $fieldAttrs[0]->newInstance();
            $relation = $relationAttrs === [] ? null : $relationAttrs[0]->newInstance();
            $fields[$prop->getName()] = self::fieldSchema($modelClass, $prop, $field, $relation);
        }

        $schema = [
            'key' => $entity->type,
            'table' => $entity->table,
            'storage' => $entity->storage,
            'id_field' => $idField,
            'fields' => $fields,
        ];
        $indexes = array_map(static fn (\ReflectionAttribute $a): array => $a->newInstance()->fields, $ref->getAttributes(Index::class));
        if ($indexes !== []) {
            $schema['indexes'] = $indexes;
        }

        return $schema;
    }

    /**
     * Refuses a model file that defines schema for a key a model class defines (one definition
     * per key): a schema key at the top or on a field. Needs only the file, not the class, so
     * TypeRegistry runs it for every class-backed key when it is built (at boot).
     *
     * @param array<string, mixed> $adminFile the model JSON file of the key
     * @throws \RuntimeException naming the file and the key it sets
     */
    public static function assertAdminOnly(array $adminFile, string $file, string $key, string $modelClass = ''): void
    {
        $tail = "{$key} is defined by " . ($modelClass !== '' ? $modelClass : 'its model class') . '; this file may only hold its admin settings';
        $file = basename($file);

        foreach (array_diff(self::SCHEMA_TYPE_KEYS, ['key', 'fields']) as $schemaKey) {
            if (array_key_exists($schemaKey, $adminFile)) {
                throw new \RuntimeException("Model file {$file} sets \"{$schemaKey}\", but {$tail}.");
            }
        }
        foreach (is_array($adminFile['fields'] ?? null) ? $adminFile['fields'] : [] as $name => $entry) {
            foreach (array_keys(is_array($entry) ? $entry : []) as $fieldKey) {
                if (in_array($fieldKey, self::SCHEMA_FIELD_KEYS, true)) {
                    throw new \RuntimeException("Model file {$file} sets \"{$fieldKey}\" on the field \"{$name}\", but {$tail}.");
                }
            }
        }
    }

    /**
     * A class-backed type with its admin file's presentation laid over it: the file's type-level
     * keys (label, list, section, ...) are added, its per-field keys merged into the class's
     * fields (the file wins for label and help). The file may not define data: one definition
     * per key, so a schema key in it is an error, never a silent override.
     *
     * @param array<string, mixed> $classSchema ModelSchema::of()
     * @param array<string, mixed> $adminFile   the model JSON file of the same key
     * @param string               $file        its path, for messages
     * @param string               $modelClass  the class, for messages
     * @return array<string, mixed>
     */
    public static function overlay(array $classSchema, array $adminFile, string $file, string $modelClass = ''): array
    {
        $key = (string) ($classSchema['key'] ?? '');
        self::assertAdminOnly($adminFile, $file, $key, $modelClass);
        $fields = is_array($adminFile['fields'] ?? null) ? $adminFile['fields'] : [];
        foreach ($fields as $name => $entry) {
            if (!isset($classSchema['fields'][$name])) {
                throw new \RuntimeException('Model file ' . basename($file) . " has settings for the field \"{$name}\", which {$key}" . ($modelClass !== '' ? " ({$modelClass})" : '') . " does not have.");
            }
        }

        $schema = $classSchema + array_diff_key($adminFile, array_flip(self::SCHEMA_TYPE_KEYS));
        foreach ($fields as $name => $entry) {
            $schema['fields'][$name] = array_merge($classSchema['fields'][$name], (array) $entry);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fieldSchema(
        string $modelClass,
        \ReflectionProperty $prop,
        Field $field,
        ?Attributes\Relation $relation,
    ): array {
        $out = ['type' => $field->type ?? ($relation !== null ? 'relation' : self::inferType($modelClass, $prop))];

        if ($field->label !== null) {
            $out['label'] = $field->label;
        }
        if ($field->help !== null) {
            $out['help'] = $field->help;
        }

        // Scenario rules (`on:create`) are decided per save and stay on the class.
        $rules = [];
        foreach ($prop->getAttributes(Validate::class) as $attr) {
            $validate = $attr->newInstance();
            if ($validate->on === null) {
                array_push($rules, ...$validate->rules);
            }
        }
        if ($rules !== []) {
            $out['validate'] = $rules;
        }

        if ($field->translatable) {
            self::assertTakesLocaleMap($modelClass, $prop);
            $out['translatable'] = true;
        }
        if ($field->unique !== false) {
            $out['unique'] = $field->unique;
        }
        if ($field->index) {
            $out['index'] = true;
        }
        if ($field->length !== null) {
            $out['length'] = $field->length;
        }
        if ($field->searchable !== null) {
            $out['searchable'] = $field->searchable;
        }
        if ($field->transform !== null) {
            $out['transform'] = $field->transform;
        }
        if ($field->default !== null) {
            $out['default'] = $field->default;
        }
        if ($relation !== null) {
            $out['relation'] = array_filter([
                'to' => $relation->to,
                'onDelete' => $relation->onDelete,
                'onSelfDelete' => $relation->onSelfDelete,
                'labelField' => $relation->labelField,
                'metaField' => $relation->metaField,
            ], static fn(mixed $v): bool => $v !== null);
        }

        return $out + $field->config;
    }

    /**
     * A translatable field reads its locale map into the property on a multilingual site, so the
     * property must take an array (and the plain value of a single-locale site).
     */
    private static function assertTakesLocaleMap(string $modelClass, \ReflectionProperty $prop): void
    {
        $type = $prop->getType();
        if ($type === null) {
            return;
        }
        $names = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($names as $named) {
            if ($named instanceof \ReflectionNamedType && in_array($named->getName(), ['array', 'mixed'], true)) {
                return;
            }
        }

        throw new \LogicException(
            "Property [{$modelClass}::\${$prop->getName()}] is translatable, so it holds a locale map ({\"en\": …, \"de\": …}) on a multilingual site: "
            . 'type it array|string|null.'
        );
    }

    private static function inferType(string $modelClass, \ReflectionProperty $prop): string
    {
        $type = $prop->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

        return match ($name) {
            'string' => 'string',
            'int', 'float' => 'number',
            'bool' => 'boolean',
            default => throw new \LogicException(
                "Property [{$modelClass}::\${$prop->getName()}] needs a field type: give the field a type: blocks, matrix, asset or relation "
                . "(or a date, select, ... as #[Field(type: …)]); its PHP type does not say."
            ),
        };
    }
}
