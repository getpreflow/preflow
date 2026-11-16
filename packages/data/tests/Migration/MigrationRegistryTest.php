<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\MigrationRegistry;

final class MigrationRegistryTest extends TestCase
{
    public function test_registry_ignores_missing_dir_and_rejects_duplicate_step(): void
    {
        $registry = new MigrationRegistry();
        $registry->addPath(sys_get_temp_dir() . '/preflow_no_such_dir_' . uniqid());
        $registry->addPath(__DIR__);

        $this->assertSame([__DIR__], $registry->paths());

        $registry->afterMigrate('sync', fn (): array => ['synced']);
        $this->assertSame(['synced'], $registry->steps()['sync']());

        $this->expectException(\RuntimeException::class);
        $registry->afterMigrate('sync', fn (): array => []);
    }

    public function test_a_step_is_sql_only_unless_registered_for_any_storage(): void
    {
        $registry = new MigrationRegistry();
        $registry->afterMigrate('tables', fn (): array => ['created table x']);
        $registry->afterMigrate('marks', fn (): array => ['marked 2 rows'], sqlOnly: false);

        $this->assertSame(['tables', 'marks'], array_keys($registry->steps()));
        $this->assertSame(['tables', 'marks'], array_keys($registry->steps(sql: true)));
        $this->assertSame(['marks'], array_keys($registry->steps(sql: false)), 'a site without SQL storage runs only the storage-neutral steps');
        $this->assertTrue($registry->isSqlOnly('tables'));
        $this->assertFalse($registry->isSqlOnly('marks'));
    }

    public function test_a_last_step_runs_after_every_other_whenever_it_was_registered(): void
    {
        $registry = new MigrationRegistry();
        $registry->afterMigrate('first', fn (): array => []);
        $registry->afterMigrate('check', fn (): array => [], last: true);
        $registry->afterMigrate('later', fn (): array => [], sqlOnly: false);

        $this->assertSame(['first', 'later', 'check'], array_keys($registry->steps()));
        $this->assertSame(['later'], array_keys($registry->steps(sql: false)));
    }
}
