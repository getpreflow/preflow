<?php

declare(strict_types=1);

namespace Preflow\Fields\Rules;

use Preflow\Fields\DateOptions;
use Preflow\Fields\Values\DateTimeValue;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A datetime field's value is the stored form: UTC ISO 8601 text with a "Z" ("2026-03-29T12:30:00Z"),
 * a real date and time. Folio turns what an editor types into it; text typed in the field's zone
 * that is a time the clocks skip (the hour lost when they go forward) is refused with a message
 * naming the time and the zone (DateTimeValue). An empty value passes ("required" decides).
 *
 * FieldsServiceProvider registers it for every "datetime" field (FieldKinds::rules()).
 *
 * @since 0.91.0
 */
final class ValidDateTime implements ValidationRule
{
    public function __construct(
        private readonly DateOptions $options = new DateOptions(),
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }

        return self::problem($value, $this->options->zone()) ?? true;
    }

    /** The refusal of one stored date-time, or null when it is fine (also used by the daterange rule). */
    public static function problem(mixed $value, \DateTimeZone $zone): ?Message
    {
        if (DateTimeValue::isStored($value)) {
            return null;
        }
        if (is_string($value) && DateTimeValue::localProblem($value, $zone) === 'gap') {
            return new Message('validation.datetime_gap', ['value' => str_replace('T', ' ', trim($value)), 'zone' => $zone->getName()]);
        }

        return new Message('validation.datetime');
    }
}
