<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\FolioText;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * How many blocks a blocks field holds: at least "min", at most "max" (BlocksFieldType::rules()).
 * Through Folio's field rules in the core (FieldKinds), it runs for a record's blocks field and for
 * one nested in a block alike, keyed by the field ("<field>", "<field>.<uid>.<child>").
 *
 * @since 0.92.0
 */
final readonly class BlockCount implements ValidationRule
{
    public function __construct(
        public ?int $min = null,
        public ?int $max = null,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : json_decode($value, true);
        }
        $count = is_array($value) ? count(array_filter($value, 'is_array')) : 0;
        if ($this->min !== null && $count < $this->min) {
            return FolioText::choice('folio.blocks.min', $this->min);
        }
        if ($this->max !== null && $count > $this->max) {
            return FolioText::choice('folio.blocks.max', $this->max);
        }

        return true;
    }
}
