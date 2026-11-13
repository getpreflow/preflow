<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\TypeDefinition;

/** The default RecordColumns: none besides the fields and the publishing keys. */
final class NoRecordColumns implements RecordColumns
{
    public function columns(TypeDefinition $def): array
    {
        return [];
    }
}
