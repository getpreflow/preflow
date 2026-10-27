<?php

declare(strict_types=1);

namespace Preflow\Folio\Storage;

use Preflow\Data\DataManager;
use Preflow\Data\Schema\RecordTables;
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
 * The id is the primary key; the working and internal tables are typed with
 * their unique indexes, the published snapshots typed without them, the
 * revisions free text (RecordTableSync, ShadowTypes). Missing tables and
 * columns are added; nothing is ever dropped or altered. tables() is what
 * Folio registers with the data layer's RecordTables.
 * JSON-stored types are left alone.
 *
 * @phpstan-import-type Entry from RecordTables
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

    /** @return list<string> the changes made and the warnings, one line each */
    public function sync(): array
    {
        return $this->records->syncAll($this->tables());
    }

    /** @return list<Entry> every table, with the system columns Folio writes besides the fields */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->catalog->all() as $listing) {
            $def = $this->registry->get($listing->key);
            $tables[] = RecordTables::entry($def, self::SYSTEM);
            if ($listing->publishing) {
                array_push($tables, ...ShadowTypes::tables($def, self::SYSTEM));
            }
        }
        foreach ($this->internal as $def) {
            $tables[] = RecordTables::entry($def, self::SYSTEM);
        }

        return $tables;
    }
}
