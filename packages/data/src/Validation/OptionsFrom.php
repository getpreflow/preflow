<?php

declare(strict_types=1);

namespace Preflow\Data\Validation;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * The rule of a select with "options_from": the value must be one of the values of the source
 * field in the same data — the record as it is being saved, or the block it sits in — so a source
 * edited in the same save counts as submitted. The source is read as values() reads it ($list: a
 * comma text of a field that holds a list, such as tags, gives its entries). An empty value passes
 * ("required" judges that). The message is the usual "Must be one of: …", or, with no values in
 * the source yet, one naming the source field ($sourceLabel, else its name).
 *
 * In the core since 0.92 (an admin package's select rule since 0.89), so every write path checks it.
 *
 * @since 0.92.0
 */
final readonly class OptionsFrom implements ValidationRule
{
    public function __construct(
        public string $source,
        public ?string $sourceLabel = null,
        /** The source holds a list (FieldKinds::holdsList()): a comma text gives its entries. */
        public bool $list = false,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string|Message
    {
        if ($value === null || $value === '') {
            return true;
        }
        $options = self::values($context->getValue($this->source), $this->list);
        if ($options === []) {
            return new Message('validation.options_from', ['field' => $this->sourceLabel ?? SelectRules::humanize($this->source)]);
        }
        if (!is_scalar($value) || !in_array((string) $value, $options, true)) {
            return new Message('validation.in', ['values' => implode(', ', $options)]);
        }

        return true;
    }

    /**
     * A source field's value as options: a list, a JSON-text list, or one text (with $list, a
     * comma text gives its entries); each entry trimmed, empty ones dropped, each value once.
     *
     * @return list<string>
     */
    public static function values(mixed $value, bool $list = false): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            $decoded = str_starts_with($trimmed, '[') ? json_decode($trimmed, true) : null;
            $value = match (true) {
                is_array($decoded) => $decoded,
                $trimmed === '' => [],
                $list => explode(',', $trimmed),
                default => [$trimmed],
            };
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            $entry = is_scalar($entry) ? trim((string) $entry) : '';
            if ($entry !== '' && !in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
