<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\Values\MatrixValue;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A required matrix holds at least one reference (0.94). "Required" alone is met by the stored
 * "[]", so the matrix kind adds this check for a required field (MatrixFieldType::fieldRules()):
 * the field is refused, form and API alike, when it holds no reference.
 *
 * @since 0.94.0
 */
final readonly class MatrixRequired implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        return MatrixValue::refs($value) === [] ? new Message('validation.required') : true;
    }
}
