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
 * refuse); no value, or both ends empty, is null. toOutput(): {start, end}, null when none is stored.
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

        return $parts === null ? null : ['start' => $parts['start'], 'end' => $parts['end']];
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?array
    {
        $parts = DateRange::parts($stored);

        return $parts === null ? null : ['start' => $parts['start'], 'end' => $parts['end']];
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
