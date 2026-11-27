<?php

declare(strict_types=1);

namespace Preflow\Data\Records;

use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;

/**
 * A package's keys in the read output (RecordOutput::toArray()) beside a record's fields: the
 * blocks package's "_layout", "_areas" and "_unplaced" for a type with page layouts, the media
 * package's "_media" when asked for. Registered on RecordParts in the package's service provider.
 *
 * Its keys are system keys: each starts with "_", is no field of the type and no other part's,
 * and is not "_id" (RecordOutput refuses any other with a \LogicException). read() gives only keys
 * keys() names for the record's type, and only JSON-safe values (an empty map as \stdClass, so it
 * encodes as {}); a key it leaves out is not in the output. What a key holds is part of the read
 * output's version (RecordOutput::VERSION).
 *
 * @since 0.93.0
 */
interface OutputPart
{
    /** @return list<string> keys it adds for this type ([] for a type it adds nothing to) */
    public function keys(TypeDefinition $def): array;

    /** @return array<string, mixed> its keys' values for this record */
    public function read(DynamicRecord $record, ReadOptions $options): array;
}
