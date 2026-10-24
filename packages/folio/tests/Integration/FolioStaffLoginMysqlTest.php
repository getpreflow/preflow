<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Testing\MysqlTestDatabase;

/** The staff login and password pages with staff, tokens, remember rows and throttle on MySQL. */
#[Group('mysql')]
final class FolioStaffLoginMysqlTest extends TestCase
{
    use StaffScaffold;

    protected function setUp(): void
    {
        MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        $this->staffSite(sql: 'mysql');
    }

    protected function tearDown(): void
    {
        $this->removeSite();
        if (MysqlTestDatabase::configured()) {
            MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        }
    }

    #[RunInSeparateProcess]
    public function test_forgot_set_password_and_remembered_sign_in_on_mysql(): void
    {
        $this->makeStaff();
        $this->makeStaff('off@site.test', active: false);
        $this->post('/folio/forgot', ['email' => 'off@site.test']);
        $this->post('/folio/forgot', ['email' => 'ADA@site.test']);
        $this->assertCount(1, $this->mails());
        $token = $this->linkToken();

        $this->assertSame('/folio', $this->post('/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getHeaderLine('Location'));
        $this->assertSame(410, $this->post('/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getStatusCode());
        $this->assertSame('1', (string) MysqlTestDatabase::pdo()->query('SELECT auth_epoch FROM staff WHERE email = \'ada@site.test\'')->fetchColumn());

        $this->post('/folio/logout', []);
        $this->useSession([]);
        $login = $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => 'a brand new password', 'remember' => '1']);
        $this->assertSame('/folio', $login->getHeaderLine('Location'));
        $this->assertNotNull($this->cookie($login, 'folio_remember'));
        $this->assertNotSame('', (string) MysqlTestDatabase::pdo()->query('SELECT last_login_at FROM staff WHERE email = \'ada@site.test\'')->fetchColumn());
        $this->assertSame('/folio/login', $this->post('/folio/login', ['email' => 'off@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'));
    }
}
