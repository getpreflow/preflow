<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Members\Console\PruneMembersCommand;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Testing\MysqlTestDatabase;

/** members:prune on MySQL: the stale rows go, the rest stays (dates are compared in PHP, not SQL). */
#[Group('mysql')]
final class PruneMembersMysqlTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        $this->scaffold(sql: 'mysql');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
        if (MysqlTestDatabase::configured()) {
            MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        }
    }

    public function test_prunes_stale_members_on_mysql(): void
    {
        $this->migrate();
        $ago = static fn (int $d): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$d} days")->format(\DATE_ATOM);
        foreach ([['u-8', [], 8, 0], ['u-6', [], 6, 0], ['d-31', ['rejected'], 31, 31], ['m', ['member'], 90, 90]] as [$id, $roles, $reg, $dec]) {
            $this->saveRecord('member', [
                'uuid' => $id, 'email' => $id . '@site.test', 'name' => $id, 'password_hash' => 'x', 'roles' => $roles,
                'registered_at' => $ago($reg), 'email_confirmed_at' => $id === 'm' ? $ago(90) : '', 'decided_at' => $dec ? $ago($dec) : '',
            ]);
        }

        [$code, $out] = $this->command(new PruneMembersCommand(fn () => $this->app()));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('deleted 1 unconfirmed, 1 declined', $out);
        $ids = MysqlTestDatabase::pdo()->query('SELECT uuid FROM member ORDER BY uuid')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['m', 'u-6'], $ids);
    }
}
