<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Folio\Console\ExtensionsApplyCommand;
use Preflow\Rbac\RoleList;
use Preflow\Testing\MysqlTestDatabase;

/**
 * The staff type on SQL (storage alias "staff"): migrate creates the table, an account is
 * created and saved through the Folio form, as the config admin.
 */
final class StaffSqlTest extends TestCase
{
    use RbacAppScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_create_and_save_on_sqlite(): void
    {
        $this->sqlSite(['path' => $this->dir . '/storage/site.sqlite'], 'sqlite');

        $this->createAndSave(fn (string $sql): array => (new \PDO('sqlite:' . $this->dir . '/storage/site.sqlite'))->query($sql)->fetch(\PDO::FETCH_ASSOC));
    }

    #[RunInSeparateProcess]
    #[Group('mysql')]
    public function test_create_and_save_on_mysql(): void
    {
        $this->sqlSite(MysqlTestDatabase::dataConfig(), 'mysql');
        MysqlTestDatabase::dropAllTables(MysqlTestDatabase::reconnect());

        try {
            $this->createAndSave(static fn (string $sql): array => MysqlTestDatabase::pdo()->query($sql)->fetch(\PDO::FETCH_ASSOC));
        } finally {
            MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        }
    }

    /** @param array<string, mixed> $driver */
    private function sqlSite(array $driver, string $name): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}');
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'], $name => $driver],
            'aliases' => ['staff' => $name],
        ], true) . ";\n");
    }

    /** @param \Closure(string): array<string, mixed> $row */
    private function createAndSave(\Closure $row): void
    {
        // The test's one app (booting a second one after its session started warns).
        $app = fn (): \Preflow\Core\Application => $this->session ??= $this->app();
        foreach ([new MigrateCommand($app), new ExtensionsApplyCommand($app)] as $command) {
            ob_start();
            try {
                $code = $command->execute([]);
            } finally {
                $out = (string) ob_get_clean();
            }
            $this->assertSame(0, $code, $out);
        }
        $this->role('r-helper', 'staff', 'helper', ['page.view']);
        $this->as('admin');

        $res = $this->send('POST', '/folio/staff', ['email' => 'Ada@Site.test', 'name' => 'Ada', 'roles' => ['', 'helper'], 'active' => '1']);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $stored = $row('SELECT uuid, email, roles, active, auth_epoch, created_at FROM staff');
        $this->assertSame('ada@site.test', $stored['email']);
        $this->assertSame(['helper'], json_decode((string) $stored['roles'], true));
        $this->assertSame('1', (string) $stored['active']);
        $this->assertSame('0', (string) $stored['auth_epoch']);
        $this->assertNotSame('', (string) $stored['created_at']);

        $id = (string) $stored['uuid'];
        $res = $this->send('POST', '/folio/staff/' . $id, ['email' => 'ada@site.test', 'name' => 'Ada L.', 'roles' => [''], 'active' => '0']);
        $this->assertSame(302, $res->getStatusCode());
        $record = $this->container()->get(DataManager::class)->findType('staff', $id);
        $this->assertSame('Ada L.', $record?->get('name'));
        $this->assertSame([], RoleList::normalize($record?->get('roles')));
        $this->assertFalse(\Preflow\Rbac\StaffManagers::active($row('SELECT active FROM staff')['active']));
        $this->assertSame((string) $stored['created_at'], (string) $record?->get('created_at'), 'created_at is kept');
    }
}
