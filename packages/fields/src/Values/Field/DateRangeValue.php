<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\DateRange;
use Preflow\Fields\Values\Link;

/**
 * A "daterange" field's value, shape "daterange": the object {start, end}. toStored(): the object
 * or JSON text of it, its ends trimmed (an end that is missing or no date is the rule's to
 * refuse); no value, or both ends empty, is null; an object of another shape is passed on as it is. toOutput(): {start, end}, null when none is stored.
 *
 * @since 0.93.0
 */
final class DateRangeValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }
        if (Link::decode($input) === null) {
            return $input;
        }
        $parts = DateRange::parts($input);
        if ($parts !== null) {
            return ['start' => $parts['start'], 'end' => $parts['end']];
        }
        $object = Link::decode($input);
        // Both ends empty (and nothing else) is no value; any other object is the client's data, for the rule.
        $empty = array_diff_key($object, ['start' => 1, 'end' => 1]) === []
            && array_filter($object, static fn (mixed $v): bool => !($v === null || (is_string($v) && trim($v) === ''))) === [];

        return $empty ? null : $object;
    }

    /** @return array{start: string, end: string}|null both ends of a valid range; null for none and for anything else stored (half a range, the wrong order) */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?array
    {
        return DateRange::fromStored($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'daterange';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('daterange');
    }
}
