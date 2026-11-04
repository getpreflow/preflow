<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;

/** A DataManager whose "default" storage is JSON in a temp dir or an in-memory SQLite, for Folio's internal tables. */
trait InternalStorage
{
    private string $storageDir = '';

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['json' => ['json'], 'sqlite' => ['sqlite']];
    }

    /** @param 'json'|'sqlite' $storage */
    private function internalStorage(string $storage, TypeDefinition ...$tables): DataManager
    {
        if ($storage === 'sqlite') {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $dm = new DataManager(drivers: ['default' => new SqliteDriver($pdo)]);
            foreach ($tables as $table) {
                (new RecordTableSync($dm))->sync($table);
            }

            return $dm;
        }
        $this->storageDir = sys_get_temp_dir() . '/folio_internal_' . bin2hex(random_bytes(4));

        return new DataManager(drivers: ['default' => new JsonFileDriver($this->storageDir)]);
    }

    private function removeInternalStorage(): void
    {
        if ($this->storageDir === '' || !is_dir($this->storageDir)) {
            return;
        }
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storageDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->storageDir);
    }
}
