<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\Validation\Rules\Nullable;
use Preflow\Validation\Rules\Required;

final class Validator
{
    /**
     * @param array<string, list<ValidationRule|string>> $rules Field => rules
     * @param array<string, mixed> $data Field => value
     */
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly array $rules,
        private readonly array $data,
        private readonly mixed $subject = null,
    ) {}

    public function validate(): ValidationResult
    {
        $errors = [];

        foreach ($this->rules as $field => $fieldRules) {
            $value = $this->data[$field] ?? null;
            $fieldErrors = $this->validateField($field, $value, $fieldRules);

            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
            }
        }

        return new ValidationResult($errors);
    }

    /**
     * @param list<ValidationRule|string> $rules
     * @return list<string>
     */
    private function validateField(string $field, mixed $value, array $rules): array
    {
        $errors = [];
        $hasNullable = false;

        // Check for nullable rule in the chain
        foreach ($rules as $rule) {
            $resolved = $rule instanceof ValidationRule ? $rule : $this->ruleFactory->resolve($rule);
            if ($resolved instanceof Nullable) {
                $hasNullable = true;
                break;
            }
        }

        // If nullable and value is null, skip all validation
        if ($hasNullable && ($value === null || $value === '')) {
            return [];
        }

        $context = new ValidationContext($field, $this->data, $this->subject);

        foreach ($rules as $rule) {
            $resolved = $rule instanceof ValidationRule ? $rule : $this->ruleFactory->resolve($rule);

            // Skip the nullable marker itself
            if ($resolved instanceof Nullable) {
                continue;
            }

            $result = $resolved->validate($value, $context);

            if ($result !== true) {
                $errors[] = $result;

                // Required failure stops the chain — no point checking format of empty value
                if ($resolved instanceof Required) {
                    break;
                }
            }
        }

        return $errors;
    }
}
