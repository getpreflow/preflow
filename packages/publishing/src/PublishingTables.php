<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;

/**
 * The tables of every type that publishes (PublishingTypes), on SQL storage: the working copies
 * (the type's table), the published snapshots ({table}__published) and the revisions
 * ({table}__revisions), each with the publishing keys and the app's RecordColumns besides the
 * fields (ShadowTypes says how each is typed). The `publishing.tables` step of `preflow migrate`
 * creates what is missing and adds missing columns; nothing is dropped or altered, so a second
 * run changes nothing. JSON-stored types need no tables.
 *
 * @phpstan-import-type Entry from RecordTables
 */
final class PublishingTables
{
    /** Keys the Publisher writes besides a type's fields (_locales: the languages a record is visible in). */
    public const array COLUMNS = ['_locales', ...Publisher::META];

    public function __construct(
        private readonly DataManager $dm,
        private readonly PublishingTypes $types,
        private readonly RecordColumns $columns,
    ) {}

    /** @return list<Entry> */
    public function tables(): array
    {
        $registry = $this->dm->getTypeRegistry();
        if ($registry === null) {
            return [];
        }
        $tables = [];
        foreach ($this->types->publishing() as $type) {
            $def = $registry->get($type);
            $extra = array_values(array_unique([...$this->columns->columns($def), ...self::COLUMNS]));
            array_push($tables, RecordTables::entry($def, $extra), ...ShadowTypes::tables($def, $extra));
        }

        return $tables;
    }

    /** @return list<string> the changes made and the warnings, one line each */
    public function sync(): array
    {
        return (new RecordTableSync($this->dm))->syncAll($this->tables());
    }
}
