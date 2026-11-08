<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Preflow\Data\Observe\DataObserver;
use Preflow\Data\Observe\SqlTables;

/**
 * Connects preflow/data to the page cache: reads become dependencies of the page rendering
 * (DependencyRecorder, only while one records), writes become changes (ChangeLog). Tags as in
 * DataTags. Bound by the Application only when the page cache is enabled; loaded only with
 * preflow/data installed.
 */
final class DataLayerObserver implements DataObserver
{
    public function __construct(
        private readonly DependencyRecorder $recorder,
        private readonly ChangeLog $changes,
    ) {}

    public function read(string $storage, string $type, ?string $id): void
    {
        if (!$this->recorder->recording()) {
            return;
        }
        $this->recorder->record($id === null ? DataTags::listing($storage, $type) : DataTags::record($storage, $type, $id));
    }

    public function wrote(string $storage, string $type, ?string $id): void
    {
        $this->changes->changed($id === null
            ? DataTags::tableChanged($storage, $type)
            : DataTags::recordChanged($storage, $type, $id));
    }

    public function rawRead(string $storage, string $sql): void
    {
        if (!$this->recorder->recording()) {
            return;
        }
        $tables = SqlTables::of($sql);
        if ($tables === null) {
            $this->recorder->record(DataTags::storage($storage));

            return;
        }
        foreach ($tables as $table) {
            $this->recorder->record(DataTags::listing($storage, $table));
        }
    }

    public function rawWrote(string $storage, string $sql): void
    {
        $tables = SqlTables::of($sql);
        // Unsure, or no table found in a statement that may change data: a write never
        // invalidates nothing, so it invalidates everything.
        if ($tables === null || ($tables === [] && !SqlTables::changesNothing($sql))) {
            $this->changes->changed([DataTags::storage($storage), TagIndex::EVERYTHING]);

            return;
        }
        $changed = [];
        foreach ($tables as $table) {
            array_push($changed, ...DataTags::tableChanged($storage, $table));
        }
        $this->changes->changed($changed);
    }
}
