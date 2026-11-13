<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\TypeDefinition;
use Preflow\Folio\Storage\TableSync;
use Preflow\Publishing\RecordColumns;

/**
 * The columns Folio writes besides a type's fields (translation fingerprints, sources and machine
 * marks, the SEO section, …): the tables of publishing types get them as Folio's own tables do.
 */
final class FolioRecordColumns implements RecordColumns
{
    public function columns(TypeDefinition $def): array
    {
        return TableSync::columns();
    }
}
