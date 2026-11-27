<?php

declare(strict_types=1);

namespace Preflow\Data\Values;

use Preflow\Data\TypeFieldDefinition;

/**
 * Optional for a FieldValue whose input may be partial (a block list or areas given in part): the
 * partial input merged onto the stored value, giving the value to store.
 *
 * @since 0.93.0
 */
interface MergesInput
{
    public function merge(mixed $stored, mixed $input, TypeFieldDefinition $field): mixed;
}
