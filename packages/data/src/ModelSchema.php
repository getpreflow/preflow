<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Relation;
use Preflow\Validation\Attributes\Validate;

/**
 * Describes a model class marked `#[Entity(type: …)]` as the schema array a model JSON
 * file holds, so a class and a file are the same thing to everything that reads types.
 */
final class ModelSchema
{
    /** Keys that define a type's data; every other type key is presentation. */
    public const array SCHEMA_TYPE_KEYS = ['key', 'table', 'storage', 'id_field', 'fields', 'matrixable', 'views'];

    /** Keys that define a field's data; every other field key is presentation (label, help, readonly, ...). */
    public const array SCHEMA_FIELD_KEYS = [
        'type', 'validate', 'translatable', 'unique', 'index', 'length', 'searchable',
        'transform', 'default', 'relation', 'options', 'accept', 'time', 'blocks', 'fields',
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

        return [
            'key' => $entity->type,
            'table' => $entity->table,
            'storage' => $entity->storage,
            'id_field' => $idField,
            'fields' => $fields,
        ];
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
            ], static fn(mixed $v): bool => $v !== null);
        }

        return $out + $field->config;
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
