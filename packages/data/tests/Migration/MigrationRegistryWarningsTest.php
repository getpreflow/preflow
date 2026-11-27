<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\MigrationRegistry;

final class MigrationRegistryWarningsTest extends TestCase
{
    public function test_warnings_are_kept_in_the_order_registered(): void
    {
        $registry = new MigrationRegistry();
        $registry->warning('a', static fn (): array => ['one']);
        $registry->warning('b', static fn (): array => ['two'], sqlOnly: true);

        $this->assertSame(['a', 'b'], array_keys($registry->warnings()));
        $this->assertSame(['a', 'b'], array_keys($registry->warnings(sql: true)));
        $this->assertSame(['a'], array_keys($registry->warnings(sql: false)), 'a SQL-only warning is not for a site without SQL storage');
        $this->assertSame(['one'], $registry->warnings()['a']());
    }

    public function test_a_name_is_registered_once(): void
    {
        $registry = new MigrationRegistry();
        $registry->warning('a', static fn (): array => []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Migration warning [a] is already registered.');
        $registry->warning('a', static fn (): array => []);
    }

    public function test_a_warning_that_throws_becomes_a_line(): void
    {
        $lines = MigrationRegistry::linesOf([
            'ok' => static fn (): array => ['fine'],
            'bad' => static fn (): array => throw new \RuntimeException('no table'),
        ]);

        $this->assertSame(['ok' => ['fine'], 'bad' => ['could not be worked out: no table']], $lines);
    }
}
