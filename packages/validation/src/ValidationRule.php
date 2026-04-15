<?php

declare(strict_types=1);

namespace Preflow\Validation;

interface ValidationRule
{
    /**
     * Validate a value.
     *
     * @return true|string True on success, error message string on failure
     */
    public function validate(mixed $value, ValidationContext $context): true|string;
}
