<?php

declare(strict_types=1);

namespace Preflow\Data;

abstract class Model
{
    /**
     * Fill model properties from an associative array.
     *
     * @param array<string, mixed> $data
     */
    public function fill(array $data): void
    {
        $meta = ModelMetadata::for(static::class);

        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                if (isset($meta->transformers[$key])) {
                    $value = $meta->transformers[$key]->fromStorage($value);
                } elseif (is_int($value) || is_string($value)) {
                    $value = self::storedBool(static::class, $key, $value);
                }
                $this->{$key} = $value;
            }
        }
    }

    /** @var array<string, array<string, bool>> class => property => whether it is declared bool (or ?bool) */
    private static array $boolProperties = [];

    /**
     * What SQL stores for a bool property (1/0, '1'/'0': SQLite and MySQL have no boolean) as a
     * bool; any other value, and the value of a property that isn't bool, as given.
     */
    private static function storedBool(string $class, string $property, int|string $value): int|string|bool
    {
        if (!isset(self::$boolProperties[$class][$property])) {
            $type = (new \ReflectionProperty($class, $property))->getType();
            self::$boolProperties[$class][$property] = $type instanceof \ReflectionNamedType && $type->getName() === 'bool';
        }
        if (!self::$boolProperties[$class][$property]) {
            return $value;
        }

        return match ((string) $value) {
            '1' => true,
            '0', '' => false,
            default => $value,
        };
    }

    /**
     * Convert model to an associative array of public properties.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $meta = ModelMetadata::for(static::class);
        $ref = new \ReflectionClass($this);
        $data = [];

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            $value = $this->{$name};

            if (isset($meta->transformers[$name])) {
                $value = $meta->transformers[$name]->toStorage($value);
            }

            $data[$name] = $value;
        }

        return $data;
    }
}
