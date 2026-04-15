<?php

declare(strict_types=1);

namespace Preflow\Validation;

final class ValidatorFactory
{
    public function __construct(
        private readonly RuleFactory $ruleFactory,
    ) {}

    /**
     * @param array<string, list<ValidationRule|string>> $rules
     * @param array<string, mixed> $data
     */
    public function make(array $rules, array $data, mixed $subject = null): Validator
    {
        return new Validator($this->ruleFactory, $rules, $data, $subject);
    }
}
