<?php

declare(strict_types=1);

namespace Preflow\Fields\Rules;

use Preflow\Fields\DateOptions;
use Preflow\Fields\Values\DateRange;
use Preflow\Fields\Values\DateTimeValue;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A daterange field's value (the stored object {start, end}, or JSON text of it) has both ends, each a
 * date ("Y-m-d") or, with "with_time", a date-time in UTC (see DateTimeValue), and the end is not
 * before the start; the refusal names both. A time the clocks skip in the field's zone is refused
 * as the datetime field does. An empty value passes ("required" decides).
 *
 * Registered for every "daterange" field (FieldKinds::rules()).
 *
 * @since 0.91.0
 */
final class ValidDateRange implements ValidationRule
{
    public function __construct(
        private readonly DateOptions $options = new DateOptions(),
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        $zone = $this->options->zone();
        $example = $this->options->withTime ? '2026-03-01 09:00 – 2026-03-07 17:00' : '2026-03-01 – 2026-03-07';
        $decoded = is_string($value) ? json_decode(trim($value), true) : $value;
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return new Message('validation.daterange', ['example' => $example]);
        }
        $parts = DateRange::parts($decoded);
        if ($parts === null) {
            return true; // both ends empty: no value
        }
        if ($parts['start'] === null || $parts['end'] === null) {
            return new Message('validation.daterange', ['example' => $example]);
        }
        foreach ([$parts['start'], $parts['end']] as $end) {
            if ($this->options->withTime) {
                $problem = ValidDateTime::problem($end, $zone);
                if ($problem !== null) {
                    return $problem->key === 'validation.datetime' ? new Message('validation.daterange', ['example' => $example]) : $problem;
                }
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1 || DateRange::point($end) === null) {
                return new Message('validation.daterange', ['example' => $example]);
            }
        }
        $range = DateRange::fromStored($parts);
        if ($range === null) {
            $show = fn (string $end): string => $this->options->withTime ? (DateTimeValue::fromStored($end)?->format('Y-m-d H:i', $zone) ?? $end) : $end;

            return new Message('validation.daterange_order', ['start' => $show($parts['start']), 'end' => $show($parts['end'])]);
        }

        return true;
    }
}
