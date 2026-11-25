<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * The rule of a select with "options_from" (0.89): the value must be one of the values of the
 * source field in the same data, the record as it is being saved (so a source edited in the same
 * form counts as submitted). An empty value passes ("required" judges that). The message is the
 * usual "Must be one of: …".
 *
 * @since 0.89
 */
final class OptionsFrom implements ValidationRule
{
    public function __construct(
        public readonly string $source,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }
        $options = SelectFieldType::sourceValues($context->getValue($this->source));
        if (!is_scalar($value) || !in_array((string) $value, $options, true)) {
            return new Message('validation.in', ['values' => implode(', ', $options)]);
        }

        return true;
    }
}
