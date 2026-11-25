<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldType;
use Preflow\Folio\FolioText;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * The rule of a select with "options_from" (0.89): the value must be one of the values of the
 * source field in the same data, the record as it is being saved (so a source edited in the same
 * form counts as submitted). The source is read through its own field type ($sourceType: a tags
 * field's old comma text gives its tags), else as SelectFieldType::sourceValues() reads it. An
 * empty value passes ("required" judges that). The message is the usual "Must be one of: …", or,
 * with no values in the source yet, one naming the source field ($sourceLabel).
 *
 * @since 0.89
 */
final class OptionsFrom implements ValidationRule
{
    public function __construct(
        public readonly string $source,
        public readonly ?FieldType $sourceType = null,
        public readonly ?string $sourceLabel = null,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string|Message
    {
        if ($value === null || $value === '') {
            return true;
        }
        $raw = $context->getValue($this->source);
        $options = SelectFieldType::sourceValues($this->sourceType !== null ? $this->sourceType->fromStorage($raw) : $raw);
        if ($options === []) {
            return FolioText::get('folio.fields.options_from_none', ['field' => $this->sourceLabel ?? (new \Preflow\Form\FieldRenderer())->humanize($this->source)]);
        }
        if (!is_scalar($value) || !in_array((string) $value, $options, true)) {
            return new Message('validation.in', ['values' => implode(', ', $options)]);
        }

        return true;
    }
}
