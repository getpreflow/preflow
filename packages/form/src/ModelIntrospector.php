<?php

declare(strict_types=1);

namespace Preflow\Form;

use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;

/**
 * What a form reads from rules: a model's (inferType(), isRequired(), getInOptions()) or, since 0.84.2,
 * a form's own rules: list for a form without a model (typeFromRules(), inOptionsFromRules()).
 */
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
        $fromRules = $this->typeFromRules($this->getFieldRules($field, $modelClass, $scenario));
        if ($fromRules !== null) {
            return $fromRules;
        }
        $ref = new \ReflectionClass($modelClass);
        if ($ref->hasProperty($field)) {
            $type = $ref->getProperty($field)->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'bool') return 'checkbox';
        }
        return 'text';
    }

    /**
     * The input type a list of rules asks for (email, url, number, select), null when none does.
     * What inferType() uses for a model, and FormBuilder for a form with rules: and no model.
     *
     * @param list<mixed> $rules
     * @since 0.84.2
     */
    public function typeFromRules(array $rules): ?string
    {
        foreach ($rules as $rule) {
            if (!is_string($rule)) continue;
            if ($rule === 'email') return 'email';
            if ($rule === 'url') return 'url';
            if ($rule === 'integer' || $rule === 'numeric') return 'number';
            if (str_starts_with($rule, 'in:')) return 'select';
        }
        return null;
    }

    /**
     * The values of an "in:" rule in a list of rules, null without one.
     *
     * @param list<mixed> $rules
     * @return list<string>|null
     * @since 0.84.2
     */
    public function inOptionsFromRules(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'in:')) return explode(',', substr($rule, 3));
        }
        return null;
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
        return $this->inOptionsFromRules($this->getFieldRules($field, $modelClass, $scenario));
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
