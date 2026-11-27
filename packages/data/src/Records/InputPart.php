<?php

declare(strict_types=1);

namespace Preflow\Data\Records;

use Preflow\Data\TypeDefinition;

/**
 * A package's keys in a write's input beside a type's fields (the blocks package's "_layout" and
 * "_areas"): the write class (RecordInput) hands the part the stored values and the input, and the
 * part merges what its keys hold. Registered on RecordParts in the package's service provider; its
 * keys follow OutputPart's rules (a leading "_", no field's, no other part's, not "_id").
 *
 * @since 0.93.0
 */
interface InputPart
{
    /** @return list<string> keys it takes for this type ([] for a type it takes none of) */
    public function keys(TypeDefinition $def): array;

    /**
     * @param array<string, mixed> $stored the record's stored values ([] for a new record)
     * @param array<string, mixed> $input  the write's input, every key of it
     * @return array<string, mixed> the values after its keys: its keys' values to store
     */
    public function merge(TypeDefinition $def, array $stored, array $input): array;
}
