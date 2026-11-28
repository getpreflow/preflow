<?php

declare(strict_types=1);

namespace Preflow\Blocks\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * How many blocks a blocks field holds: at least "min", at most "max" of the field's config
 * (0.94; Folio's rule before). BlocksServiceProvider registers it as the "blocks" kind's check
 * (FieldKinds::rules()), so every validated write counts a record's blocks field ("<field>") and
 * one nested in a block ("<field>.<uid>.<child>", through BlockValueChecks) alike, with or without
 * Folio. The value is a list of blocks or its JSON text; none (null, "") counts 0, so does what is
 * no list. Messages: validation.blocks.min, validation.blocks.max (":count", its plural form).
 *
 * @since 0.94.0
 */
final readonly class BlockCount implements ValidationRule
{
    public function __construct(
        public ?int $min = null,
        public ?int $max = null,
    ) {}

    /**
     * The rule for a blocks field's config: none without "min" and "max".
     *
     * @param array<string, mixed> $config
     * @return list<self>
     */
    public static function of(array $config): array
    {
        $min = isset($config['min']) ? (int) $config['min'] : null;
        $max = isset($config['max']) ? (int) $config['max'] : null;

        return $min === null && $max === null ? [] : [new self($min, $max)];
    }

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : json_decode($value, true);
        }
        $count = is_array($value) ? count(array_filter($value, 'is_array')) : 0;
        if ($this->min !== null && $count < $this->min) {
            return new Message('validation.blocks.min', ['count' => (string) $this->min], $this->min);
        }
        if ($this->max !== null && $count > $this->max) {
            return new Message('validation.blocks.max', ['count' => (string) $this->max], $this->max);
        }

        return true;
    }
}
