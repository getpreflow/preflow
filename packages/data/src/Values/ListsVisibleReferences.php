<?php

declare(strict_types=1);

namespace Preflow\Data\Values;

use Preflow\Data\TypeFieldDefinition;

/**
 * Optional for a ListsReferences value that holds other fields' values (preflow/blocks' blocks
 * and areas fields): the references the read side shows, leaving out what a field its show_when
 * hides holds (that field reads null). references() keeps listing every stored reference, for
 * checks such as "is this asset still in use" (a hidden value comes back when its source
 * switches back). RecordOutput::references() asks this one where a value has it.
 *
 * @since 0.93.0
 */
interface ListsVisibleReferences
{
    /** @return array<string, list<string>> target type => ids the visible fields reference */
    public function visibleReferences(mixed $stored, TypeFieldDefinition $field): array;
}
