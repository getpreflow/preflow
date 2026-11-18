<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\PlainDriver;

/** whereIn() with a subquery on another driver, and on a driver with no capability at all. */
final class PluckAcrossDriversTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_pluck_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/json', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/json/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/json/*') ?: [] as $folder) {
            @rmdir($folder);
        }
        @rmdir($this->dir . '/json');
        @rmdir($this->dir);
    }

    private function visitsType(): TypeDefinition
    {
        return new TypeDefinition('visits', 'visits', 'default', ['uuid' => new TypeFieldDefinition('uuid'), 'install' => new TypeFieldDefinition('install')]);
    }

    private function installsType(): TypeDefinition
    {
        return new TypeDefinition('installs', 'installs', 'default', ['uuid' => new TypeFieldDefinition('uuid'), 'kind' => new TypeFieldDefinition('kind')]);
    }

    public function test_a_subquery_on_another_driver_and_on_a_plain_driver(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $sqlite = new SqliteDriver($pdo);
        $pdo->exec('CREATE TABLE `visits` (`uuid` TEXT PRIMARY KEY, `install` TEXT)');
        foreach ([['v1', 'i1'], ['v2', 'i2'], ['v3', 'i3']] as [$id, $install]) {
            $sqlite->save('visits', $id, ['uuid' => $id, 'install' => $install]);
        }
        $json = new JsonFileDriver($this->dir . '/json');
        foreach (['i1' => 'web', 'i2' => 'app', 'i3' => 'web'] as $id => $kind) {
            $json->save('installs', $id, ['uuid' => $id, 'kind' => $kind]);
        }

        foreach ([$sqlite, new PlainDriver($sqlite)] as $visitsDriver) {
            foreach ([$json, new PlainDriver($json)] as $installsDriver) {
                $web = QueryBuilder::forType($installsDriver, $this->installsType())->where('kind', 'web');
                $rows = QueryBuilder::forType($visitsDriver, $this->visitsType())->whereIn('install', $web, 'uuid')->pluck('uuid');
                sort($rows);
                $this->assertSame(['v1', 'v3'], $rows);
            }
        }
    }
}
