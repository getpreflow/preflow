<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Members\MemberState;
use Preflow\Members\Members;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Members\MemberRoles;
use Preflow\Testing\MysqlTestDatabase;

/** The member type on MySQL (alias members): migrate creates the table, the service round-trips. */
#[Group('mysql')]
final class MemberMysqlTest extends TestCase
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

    public function test_member_create_and_save_on_mysql(): void
    {
        $out = $this->migrate();
        $this->assertStringContainsString('created table member', $out);

        $members = $this->container()->get(Members::class);
        $m = $members->register(' Ada@Example.com', 'Ada', 'correct horse battery', 'de', []);
        $members->confirm($m);
        $members->decide($m, 'member');

        $stored = $members->findByEmail('ada@example.com');
        $this->assertNotNull($stored);
        $this->assertSame(['member'], MemberRoles::of($stored));
        $this->assertSame(MemberState::Member, MemberState::fromRecord($stored));
        $this->assertSame('de', $stored->get('language'));
        $row = MysqlTestDatabase::pdo()->query('SELECT email, roles FROM member')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('ada@example.com', $row['email']);
        $this->assertSame(['member'], json_decode((string) $row['roles'], true));

        $members->setPassword($stored, 'a brand new password');
        $this->assertSame('1', (string) $members->find((string) $stored->getId())?->get('auth_epoch'), 'the session epoch moves on');
        $this->assertSame('1', (string) MysqlTestDatabase::pdo()->query('SELECT auth_epoch FROM member')->fetchColumn());
    }
}
