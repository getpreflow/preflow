<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Search;

require_once __DIR__ . '/Fixtures/RankedFakeDriver.php';

use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\StorageDriver;
use Preflow\Data\Tests\Search\Fixtures\RankedFakeDriver;
use Preflow\Testing\Contract\StorageDriverContract;

/** The whole storage driver contract on the fake ranked driver (JSON files plus RankedSearch). */
final class RankedFakeContractTest extends StorageDriverContract
{
    private string $dataDir;

    private RankedFakeDriver $ranked;

    protected function createDriver(): StorageDriver
    {
        $this->dataDir = sys_get_temp_dir() . '/preflow_ranked_contract_' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0777, true);

        return $this->ranked = new RankedFakeDriver(new JsonFileDriver($this->dataDir));
    }

    protected function createType(string $type, array $fields): void
    {
        // JSON files have no schema.
    }

    protected function declareFullText(string $type, array $fields): void
    {
        $this->ranked->declare($type, $fields);
    }

    protected function supportsAutoIds(): bool
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
