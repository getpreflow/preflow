<?php

declare(strict_types=1);

namespace Preflow\Data\Values;

use Preflow\Data\TypeFieldDefinition;

/**
 * Optional for a FieldValue that points at other records (a relation at its target type, a media
 * field at "asset"): the ids a stored value references, by target type, so a reader can load or
 * link them. A value without references gives [].
 *
 * @since 0.93.0
 */
interface ListsReferences
{
    /** @return array<string, list<string>> target type => ids */
    public function references(mixed $stored, TypeFieldDefinition $field): array;
}
