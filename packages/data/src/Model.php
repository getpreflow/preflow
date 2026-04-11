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
                }
                $this->{$key} = $value;
            }
        }
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
