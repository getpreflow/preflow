<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Timestamps;
use Preflow\Validation\Attributes\Validate;

final class ModelMetadata
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param array<string, Field> $fields
     * @param string[] $searchableFields
     * @param array<string, \Preflow\Data\FieldTransformer> $transformers
     * @param array<string, list<string>> $validationRules
     */
    private function __construct(
        public readonly string $modelClass,
        public readonly string $table,
        public readonly string $storage,
        public readonly string $idField,
        public readonly array $fields,
        public readonly array $searchableFields,
        public readonly bool $hasTimestamps,
        public readonly array $transformers = [],
        public readonly array $validationRules = [],
    ) {}

    /**
     * @param class-string<Model> $modelClass
     */
    public static function for(string $modelClass): self
    {
        if (isset(self::$cache[$modelClass])) {
            return self::$cache[$modelClass];
        }

        $ref = new \ReflectionClass($modelClass);

        // Read #[Entity] attribute
        $entityAttrs = $ref->getAttributes(Entity::class);
        if ($entityAttrs === []) {
            throw new \RuntimeException(
                "Model [{$modelClass}] is missing the #[Entity] attribute."
            );
        }

        $entity = $entityAttrs[0]->newInstance();

        // Scan properties
        $idField = 'uuid';
        $fields = [];
        $searchable = [];
        $hasTimestamps = false;

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();

            // Check #[Id]
            if ($prop->getAttributes(Id::class) !== []) {
                $idField = $name;
            }

            // Check #[Field]
            $fieldAttrs = $prop->getAttributes(Field::class);
            if ($fieldAttrs !== []) {
                $fieldAttr = $fieldAttrs[0]->newInstance();
                $fields[$name] = $fieldAttr;

                if ($fieldAttr->searchable) {
                    $searchable[] = $name;
                }
            }

            // Check #[Timestamps]
            if ($prop->getAttributes(Timestamps::class) !== []) {
                $hasTimestamps = true;
            }
        }

        $transformers = [];
        foreach ($fields as $name => $fieldAttr) {
            if ($fieldAttr->transform !== null) {
                $transformerClass = $fieldAttr->transform;
                if (!class_exists($transformerClass)) {
                    throw new \RuntimeException("Transformer class not found: {$transformerClass}");
                }
                $transformers[$name] = new $transformerClass();
            }
        }

        $validationRules = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $validateAttrs = $prop->getAttributes(Validate::class);
            if ($validateAttrs !== []) {
                $rules = [];
                foreach ($validateAttrs as $attr) {
                    $rules = array_merge($rules, $attr->newInstance()->rules);
                }
                $validationRules[$prop->getName()] = $rules;
            }
        }

        $meta = new self(
            modelClass: $modelClass,
            table: $entity->table,
            storage: $entity->storage,
            idField: $idField,
            fields: $fields,
            searchableFields: $searchable,
            hasTimestamps: $hasTimestamps,
            transformers: $transformers,
            validationRules: $validationRules,
        );

        self::$cache[$modelClass] = $meta;

        return $meta;
    }

    /**
     * Clear the metadata cache (for testing).
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
