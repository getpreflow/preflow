<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\TypeDefinition;

/**
 * Columns an app writes into the records of a type besides its fields and the publishing keys
 * (PublishingTables::COLUMNS): the tables of a publishing type carry them, the working copy and
 * its snapshots alike. The default adds none; Folio adds its translation and SEO keys.
 */
interface RecordColumns
{
    /** @return list<string> */
    public function columns(TypeDefinition $def): array;
}
