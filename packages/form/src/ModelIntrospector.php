<?php

declare(strict_types=1);

namespace Preflow\Form;

use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;

final class ModelIntrospector
{
    /** @param class-string<Model> $modelClass
     *  @return array<string, list<string>> */
    public function getFields(string $modelClass, ?string $scenario = null): array
    {
        $meta = ModelMetadata::for($modelClass);
        return $scenario !== null ? $meta->validationRulesForScenario($scenario) : $meta->validationRules;
    }

    /** @param class-string<Model> $modelClass */
    public function inferType(string $field, string $modelClass, ?string $scenario = null): string
    {
        $rules = $this->getFieldRules($field, $modelClass, $scenario);
        foreach ($rules as $rule) {
            if ($rule === 'email') return 'email';
            if ($rule === 'url') return 'url';
            if ($rule === 'integer' || $rule === 'numeric') return 'number';
            if (str_starts_with($rule, 'in:')) return 'select';
        }
        $ref = new \ReflectionClass($modelClass);
        if ($ref->hasProperty($field)) {
            $type = $ref->getProperty($field)->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'bool') return 'checkbox';
        }
        return 'text';
    }

    /** @param class-string<Model> $modelClass */
    public function isRequired(string $field, string $modelClass, ?string $scenario = null): bool
    {
        return in_array('required', $this->getFieldRules($field, $modelClass, $scenario), true);
    }

    /** @param class-string<Model> $modelClass
     *  @return list<string>|null */
    public function getInOptions(string $field, string $modelClass, ?string $scenario = null): ?array
    {
        foreach ($this->getFieldRules($field, $modelClass, $scenario) as $rule) {
            if (str_starts_with($rule, 'in:')) return explode(',', substr($rule, 3));
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function getValues(Model $model): array
    {
        return $model->toArray();
    }

    /** @return list<string> */
    private function getFieldRules(string $field, string $modelClass, ?string $scenario): array
    {
        return $this->getFields($modelClass, $scenario)[$field] ?? [];
    }
}
