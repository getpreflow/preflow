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
}
