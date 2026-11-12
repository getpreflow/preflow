<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\Console\InstallCommand;
use Preflow\Rbac\Console\RoleCommand;
use Preflow\Rbac\Console\RoleDeleteCommand;
use Preflow\Rbac\Console\RolesCommand;
use Preflow\Rbac\RbacResolver;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\Tests\Fixtures\ReportsProvider;

/**
 * rbac on a site without Folio (its classes can't be loaded, as when the package isn't
 * installed): roles on SQLite, `rbac:install`, the role commands and can() from the stored roles.
 */
final class CoreRbacTest extends TestCase
{
    private string $dir;
    private string|false $errorLog = false;

    protected function setUp(): void
    {
        $loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
        $loader->setPsr4('Preflow\\Folio\\', [sys_get_temp_dir() . '/no-such-folio']);

        $this->dir = sys_get_temp_dir() . '/rbac_core_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log'); // stderr fails a separate-process test
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Rbac core', 'debug' => 0, 'engine' => 'twig', 'key' => 'rbac-core-test-key'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\" . ReportsProvider::class . "::class, \\Preflow\\Rbac\\RbacServiceProvider::class];\n");
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []]],
            'session' => ['cookie' => 'rbac_core_test', 'path' => '/', 'paths' => ['/admin'], 'secure' => false],
        ], true) . ";\n");
        $this->data('sqlite');
        $this->write('config/models/.keep', '');
        $this->write('app/pages/index.twig', 'HOME');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->errorLog);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param 'sqlite'|'json' $default the default storage, where the roles go */
    private function data(string $default): void
    {
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => $default,
            'models_path' => $this->dir . '/config/models',
            'drivers' => [
                'sqlite' => ['driver' => \Preflow\Data\Driver\SqliteDriver::class, 'path' => $this->dir . '/storage/site.sqlite'],
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
            ],
        ], true) . ";\n");
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    /** A freshly booted app, as each request and each command gets. */
    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @param list<string> $args @return array{int, string} exit code, output */
    private function command(string $command, array $args = []): array
    {
        $factory = fn (): Application => $this->app();
        $cmd = match ($command) {
            'rbac:install' => new InstallCommand($factory),
            'rbac:roles' => new RolesCommand($factory),
            'rbac:role' => new RoleCommand($factory),
            'rbac:role:delete' => new RoleDeleteCommand($factory),
        };
        $this->assertInstanceOf(CommandInterface::class, $cmd);
        $this->assertSame($command, $cmd->getName());
        ob_start();
        try {
            $code = $cmd->execute($args);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }

    /** The resolver the app's Access asks. */
    private function resolver(Application $app): RbacResolver
    {
        $resolver = (new \ReflectionProperty(Access::class, 'resolver'))->getValue($app->container()->get(Access::class));
        $this->assertInstanceOf(RbacResolver::class, $resolver);

        return $resolver;
    }

    private function can(Application $app, array $roles, string $permission, string $population = 'staff'): bool
    {
        return $this->resolver($app)->allows(new TestUser(roles: $roles), $population, $permission, null);
    }

    /** @return array<string, array{label: mixed, permissions: list<string>}> "population/name" => role */
    private function byName(): array
    {
        $out = [];
        foreach ($this->app()->container()->get(DataManager::class)->queryType('rbac_role')->get()->items() as $role) {
            $out[$role->get('population') . '/' . $role->get('name')] = ['label' => $role->get('label'), 'permissions' => RoleList::normalize($role->get('permissions'))];
        }
        ksort($out);

        return $out;
    }

    private function hasSqlTable(string $table): bool
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/storage/site.sqlite');

        return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn() !== false;
    }

    /** @return list<string> */
    private function logLines(): array
    {
        return array_values(array_filter(explode("\n", (string) @file_get_contents($this->dir . '/error.log'))));
    }

    #[RunInSeparateProcess]
    public function test_existing_install_keeps_answering(): void
    {
        $this->assertFalse(class_exists(\Preflow\Folio\FolioServiceProvider::class));
        // As a 0.68 site has it: the table Folio's extension made (with Folio's system columns) and its roles.
        $pdo = new \PDO('sqlite:' . $this->dir . '/storage/site.sqlite');
        $pdo->exec('CREATE TABLE rbac_role (uuid TEXT PRIMARY KEY, name TEXT, label TEXT, population TEXT, permissions TEXT, seeded_permissions TEXT, _locales TEXT, _order TEXT)');
        $insert = $pdo->prepare('INSERT INTO rbac_role (uuid, name, label, population, permissions, seeded_permissions) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute(['r1', 'admin', 'Administrator', 'staff', '["*"]', '["*"]']);
        $insert->execute(['r2', 'editor', 'Editor', 'staff', '["page.edit"]', null]);
        $insert->execute(['r3', 'member', 'Member', 'members', '["reports.view"]', '["reports.view"]']);

        $app = $this->app();
        $this->assertTrue($this->can($app, ['admin'], 'reports.export'));
        $this->assertTrue($this->can($app, ['editor'], 'page.edit'));
        $this->assertFalse($this->can($app, ['editor'], 'reports.view'));
        $this->assertFalse($this->can($app, ['ghost'], 'page.edit'));
        $this->assertFalse($this->can($app, [], 'page.edit'));
        $this->assertTrue($this->can($app, ['member'], 'reports.view', 'members'));
        $this->assertFalse($this->can($app, ['admin'], 'reports.view', 'members'));
        $this->assertSame([], $this->logLines(), 'installed: no bootstrap');

        // rbac:install on it changes nothing.
        $before = $this->byName();
        [$code, $out] = $this->command('rbac:install');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('installed already', $out);
        $this->assertSame($before, $this->byName());
    }

    #[RunInSeparateProcess]
    public function test_before_install_staff_may_do_everything(): void
    {
        $app = $this->app();

        $this->assertTrue($this->can($app, ['editor'], 'reports.export'));
        $this->assertTrue($this->can($app, [], 'page.edit'));
        $this->assertFalse($this->can($app, ['member'], 'reports.view', 'members'));
        $this->assertSame(['*'], $this->resolver($app)->patterns(new TestUser(roles: []), 'staff'));
        $lines = $this->logLines();
        $this->assertCount(1, $lines, 'logged once per request');
        $this->assertStringContainsString('run php preflow rbac:install', $lines[0]);
        $this->assertStringNotContainsStringIgnoringCase('folio', $lines[0]);
        $this->assertFalse($this->hasSqlTable('rbac_role'), 'nothing makes the table but the install');
    }

    #[RunInSeparateProcess]
    public function test_install_creates_the_table_and_seeds_the_roles_then_they_decide(): void
    {
        $this->write('config/roles.php', "<?php\nreturn " . var_export([
            'staff' => ['editor' => ['page.edit'], 'content_lead' => 'page.edit, reports.*'],
            'members' => ['member' => ['reports.view']],
        ], true) . ";\n");

        [$code, $out] = $this->command('rbac:install');
        $this->assertSame(0, $code);
        $this->assertSame("rbac installed.\nstaff: admin, content_lead, editor\nmembers: member\n", $out);
        $this->assertTrue($this->hasSqlTable('rbac_role'));
        $expected = [
            'members/member' => ['label' => 'Member', 'permissions' => ['reports.view']],
            'staff/admin' => ['label' => 'Administrator', 'permissions' => ['*']],
            'staff/content_lead' => ['label' => 'Content lead', 'permissions' => ['page.edit', 'reports.*']],
            'staff/editor' => ['label' => 'Editor', 'permissions' => ['page.edit']],
        ];
        $this->assertSame($expected, $this->byName());

        $app = $this->app();
        $this->assertTrue($this->can($app, ['editor'], 'page.edit'));
        $this->assertFalse($this->can($app, ['editor'], 'reports.view'));
        $this->assertFalse($this->can($app, [], 'page.edit'));
        $this->assertTrue($this->can($app, ['admin'], 'reports.export'));
        $this->assertTrue($this->can($app, ['member'], 'reports.view', 'members'));

        // Again: nothing changes; an editor's change stays (the seed grants a permission once).
        $this->assertSame(0, $this->command('rbac:role', ['editor', '--revoke=page.edit'])[0]);
        [$code, $out] = $this->command('rbac:install');
        $this->assertSame(0, $code);
        $this->assertStringStartsWith("rbac was installed already; roles seeded.\n", $out);
        $this->assertSame([], $this->byName()['staff/editor']['permissions']);
        $this->assertSame($expected['staff/admin'], $this->byName()['staff/admin']);
        $this->assertCount(4, $this->byName());
    }

    /**
     * config/roles.php may name permissions nothing declares (yet): the seed grants them, so a
     * deploy doesn't depend on the order of declarations, and says so for each, in the output
     * of rbac:install and migrate and in the log.
     */
    #[RunInSeparateProcess]
    public function test_the_seed_grants_undeclared_permissions_and_reports_each(): void
    {
        $this->write('config/roles.php', "<?php\nreturn ['staff' => ['editor' => ['page.edit', 'job.view']], 'members' => ['employer' => ['job.publish', 'reports.*']]];\n");
        $line = static fn (string $permission, string $role): string => "config/roles.php: \"{$permission}\" ({$role}) is not a declared permission; granted anyway.";

        [$code, $out] = $this->command('rbac:install');

        $this->assertSame(0, $code);
        $this->assertSame("rbac installed.\n" . $line('job.view', 'staff role "editor"') . "\n" . $line('job.publish', 'members role "employer"') . "\nstaff: admin, editor\nmembers: employer\n", $out);
        $this->assertSame(['page.edit', 'job.view'], $this->byName()['staff/editor']['permissions']);
        $this->assertSame(['job.publish', 'reports.*'], $this->byName()['members/employer']['permissions']);
        $logged = array_values(array_filter($this->logLines(), static fn (string $l): bool => str_contains($l, 'not a declared permission')));
        $this->assertCount(2, $logged);
        $this->assertStringEndsWith('rbac: ' . $line('job.view', 'staff role "editor"'), $logged[0]);

        ob_start();
        try {
            $this->assertSame(0, (new \Preflow\DevTools\Command\MigrateCommand(fn (): Application => $this->app()))->execute([]));
        } finally {
            $migrated = (string) ob_get_clean();
        }
        $this->assertStringContainsString($line('job.view', 'staff role "editor"'), $migrated);
        $this->assertStringContainsString($line('job.publish', 'members role "employer"'), $migrated);
    }

    #[RunInSeparateProcess]
    public function test_migrate_seeds_only_where_rbac_is_installed(): void
    {
        $this->write('config/roles.php', "<?php\nreturn ['staff' => ['editor' => ['page.edit']]];\n");
        $migrate = function (): string {
            ob_start();
            try {
                $this->assertSame(0, (new \Preflow\DevTools\Command\MigrateCommand(fn (): Application => $this->app()))->execute([]));
            } finally {
                $out = (string) ob_get_clean();
            }

            return $out;
        };

        $this->assertStringNotContainsString('rbac', $migrate());
        $this->assertFalse($this->hasSqlTable('rbac_role'), 'without rbac:install, migrate leaves rbac uninstalled');
        $this->assertTrue($this->can($this->app(), [], 'page.edit'), 'the bootstrap still applies');

        $this->command('rbac:install');
        $this->write('config/roles.php', "<?php\nreturn ['staff' => ['editor' => ['page.edit'], 'reader' => ['reports.view']]];\n");
        $this->assertStringContainsString('seeded 1 rbac role', $migrate());
        $this->assertSame(['staff/admin', 'staff/editor', 'staff/reader'], array_keys($this->byName()));
    }

    #[RunInSeparateProcess]
    public function test_install_on_json_storage(): void
    {
        $this->data('json');
        $this->assertFalse($this->app()->container()->get(RoleStore::class)->installed());

        $this->assertSame(0, $this->command('rbac:install')[0]);

        $this->assertTrue($this->app()->container()->get(RoleStore::class)->installed());
        $this->assertSame(['staff/admin'], array_keys($this->byName()));
        $this->assertFalse($this->can($this->app(), ['editor'], 'page.edit'));
    }

    #[RunInSeparateProcess]
    public function test_roles_lists_name_label_and_patterns(): void
    {
        $this->assertSame([1, "rbac is not installed here yet; run php preflow rbac:install.\n"], $this->command('rbac:roles'));
        $this->write('config/roles.php', "<?php\nreturn ['staff' => ['editor' => ['page.edit']], 'members' => ['member' => ['reports.view']]];\n");
        $this->command('rbac:install');

        $this->assertSame([0, "admin\tAdministrator\t*\neditor\tEditor\tpage.edit\n"], $this->command('rbac:roles'));
        $this->assertSame([0, "member\tMember\treports.view\n"], $this->command('rbac:roles', ['--population=members']));
        [$code, $out] = $this->command('rbac:roles', ['--population=guests']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown population "guests"', $out);
    }

    #[RunInSeparateProcess]
    public function test_role_creates_and_changes_a_role_and_refuses_undeclared_permissions(): void
    {
        $this->assertSame(1, $this->command('rbac:role', ['reporter', '--grant=reports.view'])[0], 'not installed');
        $this->command('rbac:install');

        $this->assertSame([0, "Created staff role \"reporter\" (Reporter): reports.view, reports.*\n"], $this->command('rbac:role', ['reporter', '--grant=reports.view,reports.*']));
        $this->assertSame([0, "Changed staff role \"reporter\" (Report reader): reports.view, page.edit\n"], $this->command('rbac:role', ['reporter', '--label=Report reader', '--revoke=reports.*', '--grant=page.edit']));
        $this->assertSame([0, "Created members role \"vip\" (Vip): *\n"], $this->command('rbac:role', ['vip', '--population=members', '--grant=*']));

        // Undeclared: refused by name, nothing changes.
        $this->assertSame([1, "Not a declared permission: reports.delete, nope.*; nothing changed. To grant them anyway, add --allow-undeclared.\n"], $this->command('rbac:role', ['reporter', '--grant=reports.export,reports.delete,nope.*']));
        $this->assertSame(['reports.view', 'page.edit'], $this->byName()['staff/reporter']['permissions']);
        $this->assertSame(1, $this->command('rbac:role', ['ghost', '--grant=ghost.view'])[0]);
        $this->assertArrayNotHasKey('staff/ghost', $this->byName());

        // --allow-undeclared grants them, as config/roles.php does, and names each.
        $this->assertSame(
            [0, "Not a declared permission: ghost.view; granted anyway.\nCreated staff role \"ghost\" (Ghost): ghost.view, reports.view\n"],
            $this->command('rbac:role', ['ghost', '--grant=ghost.view,reports.view', '--allow-undeclared']),
        );

        $this->assertSame(1, $this->command('rbac:role', ['Bad Name'])[0]);
        $this->assertSame(1, $this->command('rbac:role', ['x', '--population=guests'])[0]);
        $this->assertSame(1, $this->command('rbac:role', [])[0]);

        $app = $this->app();
        $this->assertTrue($this->can($app, ['reporter'], 'page.edit'));
        $this->assertFalse($this->can($app, ['reporter'], 'reports.export'));
    }

    #[RunInSeparateProcess]
    public function test_role_delete_refuses_a_held_role_and_the_only_role_granting_everything(): void
    {
        $this->write('config/models/staff.json', (string) json_encode([
            'key' => 'staff', 'table' => 'staff', 'storage' => 'sqlite', 'id_field' => 'uuid', 'label' => 'Staff', 'users' => 'staff',
            'fields' => ['email' => ['type' => 'string'], 'roles' => ['type' => 'list'], 'active' => ['type' => 'boolean']],
        ]));
        (new \Preflow\Data\Schema\RecordTableSync($this->app()->container()->get(DataManager::class)))->sync($this->app()->container()->get(DataManager::class)->getTypeRegistry()->get('staff'));
        $this->assertSame(1, $this->command('rbac:role:delete', ['admin'])[0], 'not installed');
        $this->command('rbac:install');
        $this->command('rbac:role', ['helper', '--grant=page.edit']);
        $this->command('rbac:role', ['member', '--population=members', '--grant=reports.view']);
        $dm = $this->app()->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('staff'), ['uuid' => 's1', 'email' => 'h@site.test', 'roles' => ['helper'], 'active' => false]), false);

        $this->assertSame([1, "The role \"helper\" is held by 1 staff account; take it from them first. Nothing deleted.\n"], $this->command('rbac:role:delete', ['helper']));
        $this->assertSame([1, "The role \"admin\" is the only staff role granting everything (\"*\"); it stays. Nothing deleted.\n"], $this->command('rbac:role:delete', ['admin']));
        $this->assertSame([1, "There is no staff role \"ghost\".\n"], $this->command('rbac:role:delete', ['ghost']));
        $this->assertSame([1, "There is no staff role \"member\".\n"], $this->command('rbac:role:delete', ['member']));
        $this->assertArrayHasKey('staff/helper', $this->byName());
        $this->assertArrayHasKey('staff/admin', $this->byName());

        // Nor may it lose "*".
        $this->assertSame([1, "The role \"admin\" is the only staff role granting everything (\"*\"); it stays. Nothing changed.\n"], $this->command('rbac:role', ['admin', '--revoke=*']));
        $this->assertSame(['*'], $this->byName()['staff/admin']['permissions']);

        // Another role grants everything: admin may go; a member role goes.
        $this->command('rbac:role', ['boss', '--grant=*']);
        $this->assertSame([0, "Changed staff role \"admin\" (Administrator): page.edit\n"], $this->command('rbac:role', ['admin', '--revoke=*', '--grant=page.edit']));
        $this->assertSame([0, "Deleted staff role \"admin\".\n"], $this->command('rbac:role:delete', ['admin']));
        $this->assertSame([0, "Deleted members role \"member\".\n"], $this->command('rbac:role:delete', ['member', '--population=members']));
        $this->assertSame(['staff/boss', 'staff/helper'], array_keys($this->byName()));
    }
}
