<?php

declare(strict_types=1);

namespace Preflow\Folio\Storage;

use Preflow\Data\DataManager;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\ShadowTypes;

/**
 * Tables of SQL-stored Folio types, from their model JSON (the `folio.tables`
 * step of `preflow migrate`): the type's table, the published and revision
 * shadows of publishing types, and Folio's own internal tables it is given
 * (the extension install state). The asset table is preflow/media's own
 * media.table step.
 * On SQLite the id is TEXT PRIMARY KEY and every other column nullable TEXT;
 * on MySQL the id is VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY (ids compare
 * exactly) and every other column nullable LONGTEXT (the grammar's createRecordTable). Missing
 * tables and columns are added; nothing is ever dropped or altered.
 * JSON-stored types are left alone.
 */
final class TableSync
{
    /** System keys Folio writes besides a type's fields. */
    private const array SYSTEM = ['_locales', '_order', ...Publisher::META];

    private readonly RecordTableSync $records;

    /** @param list<TypeDefinition> $internal */
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly array $internal = [],
    ) {
        $this->records = new RecordTableSync($dm);
    }

    /** @return list<string> the changes made, one line each */
    public function sync(): array
    {
        $lines = [];
        foreach ($this->definitions() as $def) {
            array_push($lines, ...$this->records->sync($def, self::SYSTEM));
        }

        return $lines;
    }

    /** @return list<TypeDefinition> */
    private function definitions(): array
    {
        $defs = [];
        foreach ($this->catalog->all() as $listing) {
            $def = $this->registry->get($listing->key);
            $defs[] = $def;
            if ($listing->publishing) {
                $defs[] = ShadowTypes::published($def);
                $defs[] = ShadowTypes::revisions($def);
            }
        }
        array_push($defs, ...$this->internal);

        return $defs;
    }
}
