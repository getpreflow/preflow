<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A stored value a class model's typed property cannot take where taking it as its default would
 * lose data: text that is no JSON list or map for an `array` property (a legacy comma string in a
 * field that became a list). Reading the record throws instead, naming the type, the field and
 * the record, so the stored values are converted (a migration) before the property is declared.
 *
 * @since 0.77
 */
final class UnreadableValue extends \RuntimeException
{
    public function __construct(
        public readonly string $type,
        public readonly string $field,
        public readonly ?string $recordId,
        public readonly string $property,
    ) {
        parent::__construct(sprintf(
            'Type "%s", record %s: field "%s" holds a value that is not a JSON list, so %s (array) cannot take it. Convert the stored values to JSON lists (a migration) before declaring the property as an array.',
            $type,
            $recordId === null ? '(no id)' : '"' . $recordId . '"',
            $field,
            $property,
        ));
    }
}
