<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\StorageDriver;
use Preflow\Testing\Contract\StorageDriverContract;

/** The storage driver contract on JSON files (in a temporary directory). */
final class JsonContractTest extends StorageDriverContract
{
    private string $dataDir;

    protected function createDriver(): StorageDriver
    {
        $this->dataDir = sys_get_temp_dir() . '/preflow_json_contract_' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0777, true);

        return new JsonFileDriver($this->dataDir);
    }

    protected function createType(string $type, array $fields): void
    {
        // JSON files have no schema: a type's folder is made on its first save.
    }

    protected function supportsAutoIds(): bool
    {
        return false; // the caller gives every id
    }

    protected function supportsRawQuery(): bool
    {
        return false;
    }

    protected function tearDown(): void
    {
        if (isset($this->dataDir) && is_dir($this->dataDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dataDir);
        }
        parent::tearDown();
    }
}
