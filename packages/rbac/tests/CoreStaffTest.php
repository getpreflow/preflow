<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\Record\AccountLinks;
use Preflow\Auth\Record\RecordUser;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Application;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Notify\Email;
use Preflow\Notify\TransportFactory;
use Preflow\Rbac\Console\InstallCommand;
use Preflow\Rbac\Console\RoleCommand;
use Preflow\Rbac\Console\StaffCreateCommand;
use Preflow\Rbac\Console\StaffDisableCommand;
use Preflow\Rbac\Console\StaffEnableCommand;
use Preflow\Rbac\Console\StaffInviteCommand;
use Preflow\Rbac\Console\StaffListCommand;
use Preflow\Rbac\Console\StaffPasswordCommand;
use Preflow\Rbac\Console\StaffResetCommand;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\Staff\StaffAccounts;
use Preflow\Rbac\Staff\StaffRefused;
use Preflow\Rbac\Tests\Fixtures\PasswordPageProvider;
use Preflow\Rbac\Tests\Fixtures\ReportsProvider;
use Preflow\Rbac\Tests\Fixtures\UnavailablePasswordPageProvider;
use Preflow\Testing\ArraySession;

/**
 * Staff accounts on a site without Folio (its classes can't be loaded): the staff type from
 * config/auth.php, StaffAccounts and the staff commands, sign-in through the staff guard.
 * Permissions (ReportsProvider): reports.view, reports.export, page.edit.
 */
final class CoreStaffTest extends TestCase
{
    private const string PASSWORD = 'a long enough password';
    private const string LAST = 'At least one account must keep the right to manage staff and roles.';
    private const string NO_LINK_PAGE = 'No page to set a password is configured; use staff:password, or install an admin that provides one.';

    private string $dir;
    private string|false $errorLog = false;

    protected function setUp(): void
    {
        $loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
        $loader->setPsr4('Preflow\\Folio\\', [sys_get_temp_dir() . '/no-such-folio']);

        $this->dir = sys_get_temp_dir() . '/rbac_staff_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log'); // stderr fails a separate-process test
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Staff site', 'debug' => 0, 'engine' => 'twig', 'key' => 'rbac-staff-test-key'];\n");
        $this->providers();
        $this->auth();
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'sqlite',
            'models_path' => $this->dir . '/config/models',
            'drivers' => ['sqlite' => ['driver' => \Preflow\Data\Driver\SqliteDriver::class, 'path' => $this->dir . '/storage/site.sqlite']],
        ], true) . ";\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
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

    /** @param bool|'unavailable' $passwordPage a set-password page (StaffLinks), or one that can't build links now */
    private function providers(bool|string $passwordPage = false): void
    {
        $page = match ($passwordPage) {
            true => [PasswordPageProvider::class],
            'unavailable' => [UnavailablePasswordPageProvider::class],
            default => [],
        };
        $providers = [ReportsProvider::class, ...$page, \Preflow\Rbac\RbacServiceProvider::class];
        $this->write('config/providers.php', "<?php\nreturn [" . implode(', ', array_map(static fn (string $p): string => '\\' . $p . '::class', $providers)) . "];\n");
    }

    /**
     * Staff sign in through the guard "session" (population staff, remembering devices): staff
     * records, then a config user (owner@). $guard names it otherwise, and makes a members guard the default.
     */
    private function auth(bool $staffRecords = true, string $guard = 'session'): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $guards = [$guard => [
            'class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'chain', 'population' => 'staff',
            'remember' => ['cookie' => 'staff_remember', 'days' => 30, 'path' => '/'],
        ]];
        if ($guard !== 'session') {
            $guards = ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'people', 'population' => 'members']] + $guards;
        }
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => $guards,
            'providers' => ($staffRecords ? ['staff' => ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active']] : []) + [
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [['email' => 'owner@site.test', 'password_hash' => $hash, 'roles' => ['admin']]]],
                'chain' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => $staffRecords ? ['staff', 'config'] : ['config']],
                'people' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [['email' => 'ada@site.test', 'password_hash' => $hash, 'roles' => []]]],
            ],
            'session' => ['cookie' => 'rbac_staff_test', 'path' => '/', 'paths' => ['/admin'], 'secure' => false],
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

    /**
     * @param list<string> $args
     * @param string|null $stdin what the command reads (staff:password), a pipe
     * @return array{int, string} exit code, output
     */
    private function command(string $command, array $args = [], ?string $stdin = null, ?Application $app = null): array
    {
        $factory = fn (): Application => $app ?? $this->app();
        $input = null;
        if ($stdin !== null) {
            $input = fopen('php://memory', 'r+');
            fwrite($input, $stdin);
            rewind($input);
        }
        $cmd = match ($command) {
            'migrate' => new MigrateCommand($factory),
            'rbac:install' => new InstallCommand($factory),
            'rbac:role' => new RoleCommand($factory),
            'staff:list' => new StaffListCommand($factory),
            'staff:create' => new StaffCreateCommand($factory),
            'staff:password' => new StaffPasswordCommand($factory, $input),
            'staff:invite' => new StaffInviteCommand($factory),
            'staff:reset' => new StaffResetCommand($factory),
            'staff:disable' => new StaffDisableCommand($factory),
            'staff:enable' => new StaffEnableCommand($factory),
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

    /** Migrated and rbac installed, the roles reporter (reports.view) and staffer (staff.edit, reports.view), boss@ an admin with a password. */
    private function site(): void
    {
        $this->assertSame(0, $this->command('migrate')[0]); // auth's tables (links, remember-me)
        $this->assertSame(0, $this->command('rbac:install')[0]);
        $this->assertSame(0, $this->command('rbac:role', ['reporter', '--grant=reports.view'])[0]);
        $this->app()->container()->get(RoleStore::class)->put('staff', 'staffer', 'Staffer', ['staff.edit', 'reports.view'], []);
        $this->assertSame(0, $this->command('staff:create', ['boss@site.test', '--name=Boss', '--role=admin'])[0]);
        $this->assertSame(0, $this->command('staff:password', ['boss@site.test'], self::PASSWORD . "\n")[0]);
    }

    private function staff(string $email, ?Application $app = null): DynamicRecord
    {
        return ($app ?? $this->app())->container()->get(StaffAccounts::class)->find($email) ?? $this->fail("No staff {$email}");
    }

    private function request(ArraySession $session): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/')->withAttribute(SessionInterface::class, $session);
    }

    /** Signs in through the staff guard as a sign-in form does: the password checked, then the session set. */
    private function signIn(ArraySession $session, string $email, string $password = self::PASSWORD, string $guard = 'session'): bool
    {
        $c = $this->app()->container();
        $staffGuard = $c->get(AuthManager::class)->guard($guard);
        if (!$staffGuard->validate(['email' => $email, 'password' => $password])) {
            return false;
        }
        $staffGuard->login($c->get(AuthManager::class)->provider($guard)->findByCredentials(['email' => $email]), $this->request($session));

        return true;
    }

    /** Who the session is signed in as on a new request. */
    private function user(ArraySession $session, string $guard = 'session'): ?Authenticatable
    {
        return $this->app()->container()->get(AuthManager::class)->guard($guard)->user($this->request($session));
    }

    /** How many link tokens are stored. */
    private function tokens(): int
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/storage/site.sqlite');
        $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(), 'name');

        return in_array('auth_tokens', $tables, true) ? (int) $pdo->query('SELECT COUNT(*) FROM auth_tokens')->fetchColumn() : 0;
    }

    /** @return list<Email> */
    private static function mails(Application $app): array
    {
        return array_values(array_map(static fn ($s) => $s->message, $app->container()->get(TransportFactory::class)->get('mail')->sent()));
    }

    private static function refusal(\Closure $call): StaffRefused
    {
        try {
            $call();
        } catch (StaffRefused $e) {
            return $e;
        }
        self::fail('Not refused');
    }

    #[RunInSeparateProcess]
    public function test_install_create_password_then_sign_in_and_can_follows_the_stored_role(): void
    {
        $this->assertSame(0, $this->command('migrate')[0]);
        $this->assertSame(0, $this->command('rbac:install')[0]);
        $this->assertTrue(is_file($this->dir . '/storage/site.sqlite'));
        $this->assertSame(0, $this->command('rbac:role', ['reporter', '--grant=reports.view'])[0]);

        [$code, $out] = $this->command('staff:create', ['Ada@Site.test', '--name=Ada Lovelace', '--role=reporter']);
        $this->assertSame(0, $code, $out);
        $this->assertSame(
            "Created staff account ada@site.test (reporter).\nIt has no password yet: set one with php preflow staff:password ada@site.test, or mail an invitation with php preflow staff:invite ada@site.test.\n",
            $out,
        );
        $ada = $this->staff('ada@site.test');
        $this->assertSame('', (string) $ada->get('password_hash'));
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($ada->get('active')));
        $this->assertSame('0', (string) $ada->get('auth_epoch'));
        $this->assertFalse($this->signIn(new ArraySession(), 'ada@site.test', ''), 'no password: no sign-in');

        [$code, $out] = $this->command('staff:password', ['ada@site.test'], self::PASSWORD . "\n");
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString(self::PASSWORD, $out);

        $session = new ArraySession();
        $this->assertTrue($this->signIn($session, 'ada@site.test'));
        $app = $this->app();
        $user = $app->container()->get(AuthManager::class)->guard('session')->user($this->request($session));
        $this->assertInstanceOf(RecordUser::class, $user);
        $app->container()->get(AuthManager::class)->setUser($user, 'session');
        $access = $app->container()->get(Access::class);
        $this->assertTrue($access->can('reports.view'));
        $this->assertFalse($access->can('reports.export'));

        [$code, $out] = $this->command('staff:list');
        $this->assertSame(0, $code);
        $this->assertSame("ada@site.test\tAda Lovelace\treporter\tactive\n", $out);
    }

    #[RunInSeparateProcess]
    public function test_a_new_password_ends_the_other_sessions_and_voids_the_links(): void
    {
        $this->site();
        $session = new ArraySession();
        $this->assertTrue($this->signIn($session, 'boss@site.test'));
        $links = $this->app()->container()->get(AccountLinks::class);
        $boss = $this->staff('boss@site.test');
        $invite = $links->issue($boss, StaffAccounts::INVITE, 3600);
        $reset = $links->issue($boss, StaffAccounts::RESET, 3600);

        [$code, $out] = $this->command('staff:password', ['boss@site.test'], "another long password\r\n");

        $this->assertSame(0, $code, $out);
        $this->assertSame("Password set for boss@site.test; its other sessions and its links have ended.\n", $out);
        $this->assertNull($this->user($session), 'the session signed in before ended');
        $links = $this->app()->container()->get(AccountLinks::class);
        $this->assertNull($links->consume($invite, StaffAccounts::INVITE));
        $this->assertNull($links->consume($reset, StaffAccounts::RESET));
        $this->assertTrue($this->signIn(new ArraySession(), 'boss@site.test', 'another long password'));

        [$code, $out] = $this->command('staff:password', ['boss@site.test'], "short\n");
        $this->assertSame(1, $code);
        $this->assertSame("The password needs at least 10 characters.\n", $out);
        [$code, $out] = $this->command('staff:password', ['nobody@site.test'], self::PASSWORD . "\n");
        $this->assertSame([1, "No staff account has the e-mail address \"nobody@site.test\".\n"], [$code, $out]);
    }

    #[RunInSeparateProcess]
    public function test_a_disabled_account_cant_sign_in_and_its_sessions_remember_logins_and_links_end(): void
    {
        $this->site();
        $this->command('staff:create', ['help@site.test', '--role=reporter']);
        $this->command('staff:password', ['help@site.test'], self::PASSWORD . "\n");
        $session = new ArraySession();
        $this->assertTrue($this->signIn($session, 'help@site.test'));
        $app = $this->app();
        $help = $this->staff('help@site.test', $app);
        $cookie = $app->container()->get(RememberMe::class)->issue('session', (string) $help->getId(), 3600);
        $links = $app->container()->get(AccountLinks::class);
        $reset = $links->issue($help, StaffAccounts::RESET, 3600);

        [$code, $out] = $this->command('staff:disable', ['help@site.test']);

        $this->assertSame([0, "Disabled help@site.test; its sessions and links have ended.\n"], [$code, $out]);
        $this->assertNull($this->user($session), 'the live session ended');
        $this->assertFalse($this->signIn(new ArraySession(), 'help@site.test'));
        $app = $this->app();
        $this->assertNull($app->container()->get(RememberMe::class)->resolve('session', $cookie));
        $this->assertSame('2', (string) $this->staff('help@site.test', $app)->get('auth_epoch'), 'one up for the password, one for disabling');
        $this->assertStringContainsString("help@site.test\t\treporter\tdisabled", $this->command('staff:list')[1]);

        $this->assertSame(0, $this->command('staff:enable', ['help@site.test'])[0]);
        $this->assertNull($this->user($session), 'enabling brings no session back');
        $this->assertNull($this->app()->container()->get(AccountLinks::class)->consume($reset, StaffAccounts::RESET), 'nor a link');
        $this->assertTrue($this->signIn(new ArraySession(), 'help@site.test'));
    }

    #[RunInSeparateProcess]
    public function test_the_password_minimum_is_readable_and_checked_before_a_link_is_used(): void
    {
        $this->site();
        $default = $this->app()->container()->get(StaffAccounts::class);
        $this->assertSame(StaffAccounts::PASSWORD_MIN, $default->passwordMin());

        $this->write('config/rbac.php', "<?php\nreturn ['password_min' => 12];\n");
        $accounts = $this->app()->container()->get(StaffAccounts::class);
        $this->assertSame(12, $accounts->passwordMin());
        $refused = self::refusal(static fn () => $accounts->checkPassword(str_repeat('a', 11)));
        $this->assertSame(StaffRefused::PASSWORD_SHORT, $refused->reason);
        $this->assertSame('password', $refused->field);
        $accounts->checkPassword(str_repeat('a', 12));
        $boss = $this->staff('boss@site.test');
        $this->assertSame(StaffRefused::PASSWORD_SHORT, self::refusal(static fn () => $accounts->setPassword($boss, str_repeat('a', 11)))->reason);
    }

    #[RunInSeparateProcess]
    public function test_the_last_active_admin_cant_be_disabled_or_demoted(): void
    {
        $this->site();

        $this->assertSame([1, self::LAST . "\n"], $this->command('staff:disable', ['boss@site.test']));
        $accounts = $this->app()->container()->get(StaffAccounts::class);
        $boss = $accounts->find('boss@site.test');
        $refused = self::refusal(static fn () => $accounts->update($boss, ['roles' => ['reporter']]));
        $this->assertSame(StaffRefused::LAST_MANAGER, $refused->reason);
        $this->assertSame('roles', $refused->field);
        $this->assertSame(StaffRefused::LAST_MANAGER, self::refusal(static fn () => $accounts->update($boss, ['active' => false]))->reason);
        $this->assertSame(StaffRefused::LAST_MANAGER, self::refusal(static fn () => $accounts->checkDelete($boss))->reason);
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($this->staff('boss@site.test')->get('active')));
        $this->assertSame(['admin'], RoleList::normalize($this->staff('boss@site.test')->get('roles')));

        // With a second admin, one may go.
        $this->command('staff:create', ['second@site.test', '--role=admin']);
        $this->assertSame(0, $this->command('staff:disable', ['boss@site.test'])[0]);
        $accounts = $this->app()->container()->get(StaffAccounts::class);
        $this->assertSame(StaffRefused::LAST_MANAGER, self::refusal(static fn () => $accounts->update($accounts->find('second@site.test'), ['roles' => []]))->reason);
    }

    #[RunInSeparateProcess]
    public function test_delegation_limits_an_actor_not_the_console(): void
    {
        $this->site();
        $this->app()->container()->get(RoleStore::class)->put('staff', 'exporter', 'Exporter', ['reports.export'], []);
        $this->command('staff:create', ['lead@site.test', '--role=staffer']);
        $app = $this->app();
        $accounts = $app->container()->get(StaffAccounts::class);
        $lead = $app->container()->get(UserProviderInterface::class)->findByCredentials(['email' => 'lead@site.test']);
        $this->assertInstanceOf(RecordUser::class, $lead);

        $refused = self::refusal(static fn () => $accounts->create('new@site.test', 'New', ['exporter'], $lead));
        $this->assertSame(StaffRefused::GRANT, $refused->reason);
        $this->assertSame("You can't grant permissions you don't have: reports.export", $refused->getMessage());
        $this->assertNull($accounts->find('new@site.test'));

        $new = $accounts->create('new@site.test', 'New', ['reporter'], $lead);
        $this->assertSame(['reporter'], RoleList::normalize($new->get('roles')));
        $this->assertSame(StaffRefused::GRANT, self::refusal(static fn () => $accounts->update($new, ['roles' => ['reporter', 'exporter']], $lead))->reason);
        $boss = $accounts->find('boss@site.test');
        $this->assertSame(StaffRefused::ACCOUNT_ABOVE, self::refusal(static fn () => $accounts->disable($boss, $lead))->reason);
        $this->assertSame(StaffRefused::ACCOUNT_ABOVE, self::refusal(static fn () => $accounts->checkDelete($boss, $lead))->reason);
        $this->assertSame(StaffRefused::CONFIG_EMAIL, self::refusal(static fn () => $accounts->create('owner@site.test', 'O', [], $lead))->reason, 'a config user\'s address, from an actor');
        $this->assertSame(StaffRefused::UNKNOWN_ROLE, self::refusal(static fn () => $accounts->create('x@site.test', 'X', ['owner']))->reason);

        // The console grants anything there is.
        [$code, $out] = $this->command('staff:create', ['export@site.test', '--role=exporter,staffer']);
        $this->assertSame(0, $code, $out);
    }

    #[RunInSeparateProcess]
    public function test_an_email_is_unique_and_stored_lowercased(): void
    {
        $this->site();

        $this->assertSame([1, "Another account already uses this email address.\n"], $this->command('staff:create', [' BOSS@site.test ']));
        $this->assertSame([1, "This is not an e-mail address.\n"], $this->command('staff:create', ['boss']));
        $this->command('staff:create', ['two@site.test']);
        $accounts = $this->app()->container()->get(StaffAccounts::class);
        $refused = self::refusal(static fn () => $accounts->update($accounts->find('two@site.test'), ['email' => 'Boss@Site.test']));
        $this->assertSame([StaffRefused::EMAIL_TAKEN, 'email'], [$refused->reason, $refused->field]);
        $accounts->update($accounts->find('two@site.test'), ['email' => 'Three@Site.test', 'name' => 'Three']);
        $this->assertSame('Three', (string) $this->staff('three@site.test')->get('name'));
        $this->assertSame(1, count(array_filter(explode("\n", $this->command('staff:list')[1]), static fn (string $l): bool => str_starts_with($l, 'boss@'))));
    }

    #[RunInSeparateProcess]
    public function test_without_a_link_page_invite_and_reset_refuse_and_issue_nothing(): void
    {
        $this->site();
        $this->command('staff:create', ['new@site.test']);

        $this->assertSame([1, self::NO_LINK_PAGE . "\n"], $this->command('staff:invite', ['new@site.test']));
        $this->assertSame([1, self::NO_LINK_PAGE . "\n"], $this->command('staff:reset', ['boss@site.test']));
        $this->assertSame(0, $this->tokens(), 'no link was issued');
        $this->assertSame([1, "This account already has a password. Send a reset link instead.\n"], $this->command('staff:invite', ['boss@site.test']));
    }

    /** A page that can't build links (Folio without app.url): refused before any link is issued or revoked. */
    #[RunInSeparateProcess]
    public function test_a_page_that_cant_build_links_refuses_before_anything_is_issued_or_revoked(): void
    {
        $this->providers(passwordPage: 'unavailable');
        $this->site();
        $this->command('staff:create', ['new@site.test']);
        $earlier = $this->app()->container()->get(AccountLinks::class)->issue($this->staff('new@site.test'), StaffAccounts::INVITE, 3600);
        $this->assertSame(1, $this->tokens());

        $this->assertSame([1, "Set app.url to send sign-in links.\n"], $this->command('staff:invite', ['new@site.test']));
        $this->assertSame([1, "Set app.url to send sign-in links.\n"], $this->command('staff:reset', ['boss@site.test']));

        $this->assertSame(1, $this->tokens(), 'nothing issued');
        $this->assertSame((string) $this->staff('new@site.test')->getId(), (string) $this->app()->container()->get(AccountLinks::class)->consume($earlier, StaffAccounts::INVITE)?->getId(), 'nothing revoked');
    }

    #[RunInSeparateProcess]
    public function test_all_lists_the_accounts_by_email_as_staff_list_does(): void
    {
        $this->site();
        $this->command('staff:create', ['zed@site.test', '--name=Zed']);
        $this->command('staff:create', ['ada@site.test', '--name=Ada', '--role=reporter']);
        $this->assertSame(0, $this->command('staff:disable', ['zed@site.test'])[0]);

        $all = $this->app()->container()->get(StaffAccounts::class)->all();

        $this->assertSame(['ada@site.test', 'boss@site.test', 'zed@site.test'], array_map(static fn (DynamicRecord $s): string => (string) $s->get('email'), $all));
        $this->assertSame(
            [0, "ada@site.test\tAda\treporter\tno password yet\nboss@site.test\tBoss\tadmin\tactive\nzed@site.test\tZed\t(no roles)\tdisabled\n"],
            $this->command('staff:list'),
        );
    }

    /** On a terminal staff:password reads without echo (stty), and refuses rather than show the password when stty is missing. */
    #[RunInSeparateProcess]
    public function test_staff_password_on_a_terminal_hides_the_input_or_refuses(): void
    {
        $this->site();
        $this->command('staff:create', ['ada@site.test']);
        $typed = static function (string $text) {
            $input = fopen('php://memory', 'r+');
            fwrite($input, $text);
            rewind($input);

            return $input;
        };
        $run = function (StaffPasswordCommand $command): array {
            ob_start();
            try {
                $code = $command->execute(['ada@site.test']);
            } finally {
                $out = (string) ob_get_clean();
            }

            return [$code, $out];
        };

        $asked = [];
        [$code, $out] = $run(new StaffPasswordCommand(fn (): Application => $this->app(), $typed(self::PASSWORD . "\n" . self::PASSWORD . "\n"), true, static function (string $args) use (&$asked): string {
            $asked[] = $args;

            return $args === '-g' ? 'saved:settings' : '';
        }));
        $this->assertSame(0, $code, $out);
        $this->assertSame(['-g', '-echo', "'saved:settings'", '-echo', "'saved:settings'"], $asked, 'echo off for each line, the settings restored');
        $this->assertTrue($this->signIn(new ArraySession(), 'ada@site.test'));

        [$code, $out] = $run(new StaffPasswordCommand(fn (): Application => $this->app(), $typed("another password!\nanother password!\n"), true, static fn (string $args): string => ''));
        $this->assertSame(1, $code);
        $this->assertStringStartsWith("This terminal can't hide what you type (stty is not available), so no password is asked for.", $out);
        $this->assertTrue($this->signIn(new ArraySession(), 'ada@site.test'), 'the password is unchanged');
    }

    #[RunInSeparateProcess]
    public function test_invite_and_reset_mail_the_bound_page_without_naming_folio(): void
    {
        $this->providers(passwordPage: true);
        $this->site();
        $this->command('staff:create', ['new@site.test', '--name=Newbie']);
        $app = $this->app();

        [$code, $out] = $this->command('staff:invite', ['new@site.test'], app: $app);

        $this->assertSame([0, "Invitation sent to new@site.test.\n"], [$code, $out]);
        $mail = self::mails($app)[0];
        $this->assertSame(['new@site.test'], $mail->to);
        $this->assertSame('Your account for Staff site', $mail->subject);
        $this->assertStringContainsString("Hi Newbie,\n\nyou were invited to edit Staff site.", $mail->text);
        $this->assertStringContainsString('7 days', $mail->text);
        foreach ([$mail->subject, $mail->text, (string) $mail->html] as $part) {
            $this->assertStringNotContainsStringIgnoringCase('folio', $part);
        }
        preg_match('~https://site\.test/set-password/([A-Za-z0-9_-]+)~', $mail->text, $m);
        $new = $this->staff('new@site.test');
        $this->assertSame((string) $new->getId(), (string) $this->app()->container()->get(AccountLinks::class)->consume($m[1] ?? '', StaffAccounts::INVITE)?->getId());

        $app = $this->app();
        $this->assertSame([0, "Reset link sent to boss@site.test.\n"], $this->command('staff:reset', ['boss@site.test'], app: $app));
        $reset = self::mails($app)[0];
        $this->assertSame('Reset your password for Staff site', $reset->subject);
        $this->assertStringContainsString('one hour', $reset->text);
        $this->assertStringContainsString('https://site.test/set-password/', $reset->text);
    }

    #[RunInSeparateProcess]
    public function test_the_staff_guard_comes_from_config_rbac(): void
    {
        $this->auth(guard: 'staff');
        $this->write('config/rbac.php', "<?php\nreturn ['guard' => 'staff'];\n");
        $this->site();

        $this->assertSame('staff', $this->app()->container()->get(StaffAccounts::class)->guard());
        $this->assertFalse($this->signIn(new ArraySession(), 'boss@site.test', self::PASSWORD, 'session'), 'the members guard knows no staff');
        $this->assertTrue($this->signIn(new ArraySession(), 'boss@site.test', self::PASSWORD, 'staff'));
    }

    #[RunInSeparateProcess]
    public function test_without_a_staff_record_provider_there_is_no_staff_type(): void
    {
        $this->auth(staffRecords: false);
        $this->assertSame(0, $this->command('rbac:install')[0]);

        $this->assertFalse($this->app()->container()->get(DataManager::class)->getTypeRegistry()->has('staff'));
        [$code, $out] = $this->command('staff:create', ['ada@site.test']);
        $this->assertSame(1, $code);
        $this->assertSame("This site has no staff accounts: config/auth.php names no user provider of class Preflow\\Auth\\Record\\RecordUserProvider with 'type' => 'staff'.\n", $out);
    }

    #[RunInSeparateProcess]
    public function test_before_install_the_staff_commands_say_so(): void
    {
        $this->assertSame([1, "rbac is not installed here yet; run php preflow rbac:install first.\n"], $this->command('staff:list'));
        $this->assertFalse(is_file($this->dir . '/storage/site.sqlite') && in_array('staff', array_column(
            (new \PDO('sqlite:' . $this->dir . '/storage/site.sqlite'))->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(),
            'name',
        ), true), 'no staff table before install');
    }

    /**
     * rbac installed, the staff table not made yet (a site whose staff type came later, or a
     * Folio site before migrate): the staff commands say so instead of failing on the query,
     * and a role command that looks at the accounts finds none.
     */
    #[RunInSeparateProcess]
    public function test_before_the_staff_table_exists_the_staff_commands_say_so(): void
    {
        $this->assertSame(0, $this->command('rbac:install')[0]);
        $this->assertSame(0, $this->command('rbac:role', ['reporter', '--grant=reports.view'])[0]);
        (new \PDO('sqlite:' . $this->dir . '/storage/site.sqlite'))->exec('DROP TABLE staff');

        $message = "No staff table yet: run php preflow rbac:install (or php preflow migrate on a Folio site).\n";
        foreach ([['staff:list', []], ['staff:create', ['ada@site.test']], ['staff:invite', ['ada@site.test']], ['staff:disable', ['ada@site.test']]] as [$command, $args]) {
            $this->assertSame([1, $message], $this->command($command, $args), $command);
        }
        $delete = new \Preflow\Rbac\Console\RoleDeleteCommand(fn (): Application => $this->app());
        ob_start();
        $code = $delete->execute(['reporter']);
        $this->assertSame([0, "Deleted staff role \"reporter\".\n"], [$code, (string) ob_get_clean()]);

        $this->assertSame(0, $this->command('rbac:install')[0]);
        $this->assertSame([0, "No staff accounts yet. Create one with php preflow staff:create <email> --role=admin.\n"], $this->command('staff:list'));
    }
}
