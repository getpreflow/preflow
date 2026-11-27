<?php

declare(strict_types=1);

namespace Preflow\Data\Values;

use Preflow\Data\TypeFieldDefinition;

/**
 * Optional for a FieldValue whose stored value is cleaned on every typed write (rich text's HTML):
 * clean() gets the value as written, a translatable field's map per locale included, and gives
 * the value to store. Cleaning is idempotent: a clean value keeps its bytes.
 *
 * @since 0.93.0
 */
interface CleansOnWrite
{
    public function clean(mixed $value, TypeFieldDefinition $field): mixed;
}
