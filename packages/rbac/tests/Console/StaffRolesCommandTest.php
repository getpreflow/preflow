<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests\Console;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Core\Application;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Rbac\Console\InstallCommand;
use Preflow\Rbac\Console\RoleCommand;
use Preflow\Rbac\Console\StaffCreateCommand;
use Preflow\Rbac\Console\StaffRolesCommand;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\Staff\StaffAccounts;
use Preflow\Rbac\Tests\Fixtures\ReportsProvider;
use Preflow\Testing\ChecksFrameBalance;

/**
 * staff:roles on a site without Folio: grant, revoke, replace and show an existing account's
 * roles through StaffAccounts (permissions of ReportsProvider: reports.view, reports.export, page.edit).
 */
final class StaffRolesCommandTest extends TestCase
{
    use ChecksFrameBalance;

    private const string LAST = 'At least one account must keep the right to manage staff and roles.';

    private string $dir;
    private string|false $errorLog = false;

    protected function setUp(): void
    {
        $loader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
        $loader->setPsr4('Preflow\\Folio\\', [sys_get_temp_dir() . '/no-such-folio']);

        $this->dir = sys_get_temp_dir() . '/rbac_staff_roles_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Staff site', 'debug' => 0, 'engine' => 'twig', 'key' => 'rbac-staff-test-key'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\" . ReportsProvider::class . '::class, \\' . \Preflow\Rbac\RbacServiceProvider::class . "::class];\n");
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'chain', 'population' => 'staff']],
            'providers' => [
                'staff' => ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [['email' => 'owner@site.test', 'password_hash' => $hash, 'roles' => ['admin']]]],
                'chain' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => ['staff', 'config']],
            ],
            'session' => ['cookie' => 'rbac_staff_test', 'path' => '/', 'paths' => ['/admin'], 'secure' => false],
        ], true) . ";\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'sqlite',
            'models_path' => $this->dir . '/config/models',
            'drivers' => ['sqlite' => ['driver' => \Preflow\Data\Driver\SqliteDriver::class, 'path' => $this->dir . '/storage/site.sqlite']],
        ], true) . ";\n");
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

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /**
     * @param list<string> $args
     * @return array{int, string} exit code, output
     */
    private function command(string $command, array $args = []): array
    {
        $factory = fn (): Application => $this->app();
        $cmd = match ($command) {
            'migrate' => new MigrateCommand($factory),
            'rbac:install' => new InstallCommand($factory),
            'rbac:role' => new RoleCommand($factory),
            'staff:create' => new StaffCreateCommand($factory),
            'staff:roles' => new StaffRolesCommand($factory),
        };
        $this->assertSame($command, $cmd->getName());
        ob_start();
        try {
            $code = $cmd->execute($args);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }

    /** rbac installed; roles reporter (reports.view) and exporter (reports.export); boss@ the only admin, ada@ without roles. */
    private function site(): void
    {
        $this->assertSame(0, $this->command('migrate')[0]);
        $this->assertSame(0, $this->command('rbac:install')[0]);
        $this->assertSame(0, $this->command('rbac:role', ['reporter', '--grant=reports.view'])[0]);
        $this->assertSame(0, $this->command('rbac:role', ['exporter', '--grant=reports.export'])[0]);
        $this->assertSame(0, $this->command('staff:create', ['boss@site.test', '--role=admin'])[0]);
        $this->assertSame(0, $this->command('staff:create', ['ada@site.test'])[0]);
    }

    /** @return list<string> */
    private function roles(string $email): array
    {
        $staff = $this->app()->container()->get(StaffAccounts::class)->find($email) ?? $this->fail("No staff {$email}");

        return RoleList::normalize($staff->get('roles'));
    }

    #[RunInSeparateProcess]
    public function test_granting_to_an_account_without_roles_gives_it_the_permission(): void
    {
        $this->site();
        $this->assertSame([], $this->roles('ada@site.test'));

        [$code, $out] = $this->command('staff:roles', ['ada@site.test', '--grant=reporter']);
        $this->assertSame(0, $code, $out);
        $this->assertSame("ada@site.test now has the roles: reporter\n", $out);
        $this->assertSame(['reporter'], $this->roles('ada@site.test'));

        $app = $this->app();
        $c = $app->container();
        $user = $c->get(\Preflow\Auth\UserProviderInterface::class)->findByCredentials(['email' => 'ada@site.test']);
        $this->assertNotNull($user);
        // Signed in as the request's identity middleware does it: the render's viewer.
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->as(new \Preflow\Core\Context\Viewer(['session' => $user], 'session')), function () use ($c): void {
            $this->assertTrue($c->get(Access::class)->can('reports.view'));
            $this->assertFalse($c->get(Access::class)->can('reports.export'));
        });

        $this->assertSame(0, $this->command('staff:roles', ['ada@site.test', '--grant=exporter,reporter'])[0]);
        $this->assertSame(['reporter', 'exporter'], $this->roles('ada@site.test'));
    }

    #[RunInSeparateProcess]
    public function test_revoke_removes_roles_and_grant_with_revoke_works_together(): void
    {
        $this->site();
        $this->command('staff:roles', ['ada@site.test', '--grant=reporter,exporter']);

        [$code, $out] = $this->command('staff:roles', ['ada@site.test', '--revoke=reporter']);
        $this->assertSame([0, "ada@site.test now has the roles: exporter\n"], [$code, $out]);

        $this->assertSame(0, $this->command('staff:roles', ['ada@site.test', '--grant=reporter', '--revoke=exporter'])[0]);
        $this->assertSame(['reporter'], $this->roles('ada@site.test'));

        [$code, $out] = $this->command('staff:roles', ['ada@site.test', '--revoke=reporter']);
        $this->assertSame([0, "ada@site.test now has no roles.\n"], [$code, $out]);
    }

    #[RunInSeparateProcess]
    public function test_role_replaces_the_list(): void
    {
        $this->site();
        $this->command('staff:roles', ['ada@site.test', '--grant=reporter']);

        [$code, $out] = $this->command('staff:roles', ['ada@site.test', '--role=exporter']);
        $this->assertSame([0, "ada@site.test now has the roles: exporter\n"], [$code, $out]);
        $this->assertSame(['exporter'], $this->roles('ada@site.test'));

        $this->assertSame(0, $this->command('staff:roles', ['ada@site.test', '--role='])[0]);
        $this->assertSame([], $this->roles('ada@site.test'));
    }

    #[RunInSeparateProcess]
    public function test_without_options_it_prints_the_roles_and_changes_nothing(): void
    {
        $this->site();
        $this->assertSame([0, "ada@site.test has no roles.\n"], $this->command('staff:roles', ['ada@site.test']));
        $this->command('staff:roles', ['ada@site.test', '--grant=reporter']);
        $this->assertSame([0, "ada@site.test has the roles: reporter\n"], $this->command('staff:roles', ['ada@site.test']));
        $this->assertSame(['reporter'], $this->roles('ada@site.test'));
    }

    #[RunInSeparateProcess]
    public function test_revoking_the_last_managers_role_is_refused_and_nothing_changes(): void
    {
        $this->site();

        foreach ([['--revoke=admin'], ['--role=reporter']] as $options) {
            [$code, $out] = $this->command('staff:roles', ['boss@site.test', ...$options]);
            $this->assertSame([1, self::LAST . "\n"], [$code, $out], implode(' ', $options));
            $this->assertSame(['admin'], $this->roles('boss@site.test'));
        }
    }

    #[RunInSeparateProcess]
    public function test_an_unknown_role_is_refused_and_nothing_changes(): void
    {
        $this->site();
        $this->command('staff:roles', ['ada@site.test', '--grant=reporter']);

        [$code, $out] = $this->command('staff:roles', ['ada@site.test', '--grant=nonesuch']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('nonesuch', $out);
        $this->assertSame(['reporter'], $this->roles('ada@site.test'));
    }

    #[RunInSeparateProcess]
    public function test_role_together_with_grant_or_revoke_is_a_usage_error(): void
    {
        $this->site();

        foreach ([['--role=reporter', '--grant=exporter'], ['--role=reporter', '--revoke=exporter']] as $options) {
            [$code, $out] = $this->command('staff:roles', ['ada@site.test', ...$options]);
            $this->assertSame(2, $code);
            $this->assertStringStartsWith('Usage: staff:roles', $out);
            $this->assertSame([], $this->roles('ada@site.test'));
        }
        [$code] = $this->command('staff:roles', []);
        $this->assertSame(2, $code, 'no e-mail');
    }

    #[RunInSeparateProcess]
    public function test_an_unknown_email_exits_1(): void
    {
        $this->site();
        $this->assertSame([1, "No staff account has the e-mail address \"nobody@site.test\".\n"], $this->command('staff:roles', ['nobody@site.test', '--grant=reporter']));
    }

    #[RunInSeparateProcess]
    public function test_before_install_it_says_so(): void
    {
        $this->assertSame([1, "rbac is not installed here yet; run php preflow rbac:install first.\n"], $this->command('staff:roles', ['ada@site.test', '--grant=reporter']));
    }
}
