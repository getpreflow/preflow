<?php

declare(strict_types=1);

namespace Preflow\Folio\Storage;

use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Data\DataManager;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\PublishingTypes;
use Preflow\Publishing\ShadowTypes;
use Preflow\Folio\Seo\SeoSection;

/**
 * Tables of SQL-stored Folio types, from their model JSON (the `folio.tables`
 * step of `preflow migrate`): the type's table, the published and revision
 * shadows of publishing types, and Folio's own internal tables it is given
 * (the extension install state). The asset table is preflow/media's own
 * media.table step.
 * The id is the primary key; the working and internal tables are typed with
 * their unique indexes, the published snapshots typed without them, the
 * revisions free text (RecordTableSync, ShadowTypes). Missing tables and
 * columns are added; nothing is ever dropped or altered.
 *
 * The tables of publishing types (PublishingTypes, as the Publisher asks)
 * are preflow/publishing's (PublishingTables, its `publishing.tables` step
 * and RecordTables entries), with Folio's SYSTEM columns through
 * FolioRecordColumns: own() — what Folio registers with the data layer's
 * RecordTables, and syncOwn(), the `folio.tables` step — leaves them out, so
 * each table is listed and made by one step. sync() makes them too, in the
 * same shape (folio:extensions:apply, which runs no migrate steps).
 * JSON-stored types are left alone.
 *
 * A type with page layouts ("layouts", since 0.92) has two columns more, PageLayouts::COLUMNS
 * ("_layout", "_areas"; columnsFor()), on its table and its published snapshots; every other
 * table keeps exactly the columns it had.
 *
 * @phpstan-import-type Entry from RecordTables
 */
final class TableSync
{
    /** System keys Folio writes besides a type's fields (columns() without the repeated _order). */
    public const array SYSTEM = ['_locales', TranslationStatus::KEY, TranslationStatus::SOURCES, TranslationStatus::MACHINE, '_order', SeoSection::KEY, ...Publisher::TABLE_META];

    private readonly RecordTableSync $records;

    /**
     * @param list<TypeDefinition> $internal
     * @param PageLayouts|null     $layouts  the types' page layouts (preflow/blocks binds them); without, no type has any
     */
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly PublishingTypes $types,
        private readonly array $internal = [],
        private readonly ?PageLayouts $layouts = null,
    ) {
        $this->records = new RecordTableSync($dm);
    }

    /** @return list<string> the changes made and the warnings, one line each */
    public function sync(): array
    {
        return $this->records->syncAll($this->tables());
    }

    /** @return list<string> as sync(), for own() only (the `folio.tables` step; `publishing.tables` makes the rest) */
    public function syncOwn(): array
    {
        return $this->records->syncAll($this->own());
    }

    /** @return list<Entry> every table, with the system columns Folio writes besides the fields */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->catalog->all() as $listing) {
            $def = $this->registry->get($listing->key);
            $tables[] = RecordTables::entry($def, $this->columnsFor($def));
            if ($this->types->publishes($listing->key)) {
                array_push($tables, ...ShadowTypes::tables($def, $this->columnsFor($def)));
            }
        }
        foreach ($this->internal as $def) {
            $tables[] = RecordTables::entry($def, self::columns());
        }

        return $tables;
    }

    /** @return list<string> SYSTEM, each key once: the extra columns of every Folio table (FolioRecordColumns gives them to preflow/publishing) */
    public static function columns(): array
    {
        return array_values(array_unique(self::SYSTEM));
    }

    /**
     * The extra columns of a type's tables: columns(), and for a type with page layouts
     * PageLayouts::COLUMNS too.
     *
     * @return list<string>
     * @since 0.92.0
     */
    public function columnsFor(TypeDefinition $def): array
    {
        return self::columnsOf($def, $this->layouts);
    }

    /**
     * columnsFor() with the page layouts given: FolioRecordColumns, which preflow/publishing asks
     * for the tables of publishing types, shares it.
     *
     * @return list<string>
     * @internal Folio's table columns
     */
    public static function columnsOf(TypeDefinition $def, ?PageLayouts $layouts): array
    {
        return $layouts?->forType($def->key) === null ? self::columns() : [...self::columns(), ...PageLayouts::COLUMNS];
    }

    /** @return list<Entry> the tables but those of publishing types (preflow/publishing lists them) */
    public function own(): array
    {
        $tables = [];
        foreach ($this->catalog->all() as $listing) {
            if (!$this->types->publishes($listing->key)) {
                $def = $this->registry->get($listing->key);
                $tables[] = RecordTables::entry($def, $this->columnsFor($def));
            }
        }
        foreach ($this->internal as $def) {
            $tables[] = RecordTables::entry($def, self::columns());
        }

        return $tables;
    }
}
