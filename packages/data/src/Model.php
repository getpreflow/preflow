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
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
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
        $ref = new \ReflectionClass($this);
        $data = [];

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isInitialized($this)) {
                $data[$prop->getName()] = $prop->getValue($this);
            }
        }

        return $data;
    }
}
