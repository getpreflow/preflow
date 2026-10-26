<?php

declare(strict_types=1);

namespace Preflow\Validation;

interface ValidationRule
{
    /**
     * Validate a value.
     *
     * A failure is a message: a Message (a key of the "validation" group, or of another group,
     * with its parameters), which the validator translates when it has a translator; or a
     * plain string, used as it is.
     *
     * @return true|string|Message True on success, the message on failure
     */
    public function validate(mixed $value, ValidationContext $context): true|string|Message;
}
