<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Users\StaffPasswords;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * The life of a staff account: invited on create, invite and reset links sent by those who may
 * edit staff, disabled and enabled (with the same delegation and lock-out rules as a save), and
 * the account's own password page. Roles: admin ['*'], manager (staff and roles), editor (staff
 * and pages, not roles), helper (pages, view). Accounts (password "secret"): boss@ (admin),
 * ed@ (editor), help@ (helper), none@ (no roles); mgr@ (manager) with $twoManagers.
 */
final class StaffLifecycleTest extends TestCase
{
    use RbacAppScaffold;

    private const string ACCOUNT = "You can't change an account with roles you can't grant.";
    private const string LAST = 'At least one account must keep the right to manage staff and roles.';
    private const string NEW_PASSWORD = 'a brand new password';

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function lifecycleSite(bool $twoManagers = false, bool $mail = true, bool $url = true): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}');
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => [
                'class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'chain', 'population' => 'staff',
                'remember' => ['cookie' => 'folio_remember', 'days' => 30, 'path' => '/folio'],
            ]],
            'providers' => [
                'staff' => ['class' => \Preflow\Folio\Users\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ]],
                'chain' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => ['staff', 'config']],
            ],
            'session' => ['cookie' => 'rbac_lifecycle_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Rbac', 'debug' => 1, 'engine' => 'twig', 'key' => 'rbac-app-test-key'" . ($url ? ", 'url' => 'https://site.test'" : '') . "];\n");
        if ($mail) {
            $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'folio@site.test']]];\n");
        }
        $this->install();
        $this->role('r-manager', 'staff', 'manager', ['folio.dashboard', 'staff.*', 'rbac_role.*']);
        $this->role('r-editor', 'staff', 'editor', ['folio.dashboard', 'staff.*', 'page.*']);
        $this->role('r-helper', 'staff', 'helper', ['page.view']);
        $this->account('s-boss', 'boss', ['admin']);
        $this->account('s-ed', 'ed', ['editor']);
        $this->account('s-help', 'help', ['helper']);
        $this->account('s-none', 'none', []);
        if ($twoManagers) {
            $this->account('s-mgr', 'mgr', ['manager']);
        }
        // As run(): queued mails go out after the response (flush() sends them here).
        (new \ReflectionProperty(\Preflow\Core\Application::class, 'emitting'))->setValue($this->session, true);
    }

    /** @param list<string> $roles */
    private function account(string $id, string $name, array $roles, bool $active = true, ?string $password = 'secret'): void
    {
        $dm = $this->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('staff'), [
            'uuid' => $id, 'email' => "{$name}@site.test", 'name' => ucfirst($name), 'roles' => $roles, 'active' => $active,
            'password_hash' => $password === null ? '' : password_hash($password, PASSWORD_DEFAULT), 'auth_epoch' => '0',
        ]), false);
    }

    /** Signs in with this browser's session (a GET of the login page first, for its CSRF token). */
    private function signIn(string $email, string $password = 'secret', bool $remember = false): ResponseInterface
    {
        $this->session ??= $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';

        return $this->session->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => $email, 'password' => $password,
        ] + ($remember ? ['remember' => '1'] : [])));
    }

    /** Switches the browser: the session of another one (an empty one for a new browser). @return array<string, mixed> the one left */
    private function useSession(array $session): array
    {
        $left = $_SESSION ?? [];
        $_SESSION = $session + ['_flash' => ['current' => [], 'previous' => []]];
        if (!isset($_SESSION['_csrf_token'])) {
            $this->get('/folio/login'); // starts the session's CSRF token
        }
        $this->token = (string) ($_SESSION['_csrf_token'] ?? '');

        return $left;
    }

    /** @param array<string, string> $cookies */
    private function get(string $uri, array $cookies = []): ResponseInterface
    {
        return $this->session->handle((new Psr17Factory())->createServerRequest('GET', $uri)->withCookieParams($cookies));
    }

    private function action(string $id, string $name): ResponseInterface
    {
        return $this->send('POST', '/folio/staff/' . $id . '/_action/' . $name);
    }

    private static function text(ResponseInterface $res): string
    {
        return html_entity_decode((string) $res->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The flash the next admin page shows. */
    private function flash(string $id): string
    {
        return self::text($this->send('GET', '/folio/staff/' . $id . '/edit'));
    }

    private function flush(): int
    {
        return $this->container()->get(Notifier::class)->flushDeferred();
    }

    /** @return list<Email> */
    private function mails(): array
    {
        return array_values(array_map(
            static fn ($s) => $s->message,
            $this->container()->get(TransportFactory::class)->get('mail')->sent(),
        ));
    }

    private function linkToken(int $mail = 0): string
    {
        preg_match('~https://site\.test/folio/password/([A-Za-z0-9_-]+)~', $this->mails()[$mail]->text ?? '', $m);

        return $m[1] ?? $this->fail("No set-password link in mail {$mail}");
    }

    private function staffByEmail(string $email): DynamicRecord
    {
        $record = $this->container()->get(DataManager::class)->queryType('staff')->where('email', $email)->first();

        return $record instanceof DynamicRecord ? $record : $this->fail('No staff ' . $email);
    }

    private function setPassword(string $token, string $password = self::NEW_PASSWORD): ResponseInterface
    {
        return $this->send('POST', '/folio/password', ['token' => $token, 'password' => $password, 'repeat' => $password]);
    }

    #[RunInSeparateProcess]
    public function test_a_new_account_is_invited_and_cant_sign_in_before_it_sets_a_password(): void
    {
        $this->lifecycleSite();
        $this->as('boss');

        $res = $this->send('POST', '/folio/staff', ['email' => 'New@site.test', 'name' => 'Newbie', 'roles' => [''], 'active' => '1']);
        $this->assertSame(302, $res->getStatusCode(), self::text($res));
        $new = $this->staffByEmail('new@site.test');
        $this->assertSame('', (string) $new->get('password_hash'));
        $this->assertSame([], $this->mails(), 'the mail goes after the answer');
        $this->assertStringContainsString('Invitation sent to new@site.test.', self::text($this->send('GET', '/folio/staff')));

        $this->assertSame(1, $this->flush());
        $mail = $this->mails()[0];
        $this->assertSame(['new@site.test'], $mail->to);
        $this->assertStringContainsString('Hi Newbie', $mail->text);
        $this->assertStringContainsString('7 days', $mail->text);

        $this->useSession([]);
        $this->assertSame('/folio/login', $this->signIn('new@site.test', '')->getHeaderLine('Location'));
        $this->assertSame('/folio/login', $this->signIn('new@site.test', 'secret')->getHeaderLine('Location'));

        $this->assertSame('/folio', $this->setPassword($this->linkToken())->getHeaderLine('Location'));
        $this->useSession([]);
        $this->assertSame('/folio', $this->signIn('new@site.test', self::NEW_PASSWORD)->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_failed_invitation_keeps_the_new_account_and_says_so(): void
    {
        $this->lifecycleSite(mail: false); // no config/notify.php: nothing can be queued
        $this->as('boss');

        $res = $this->send('POST', '/folio/staff', ['email' => 'new@site.test', 'name' => 'Newbie', 'roles' => [''], 'active' => '1']);

        $this->assertSame(302, $res->getStatusCode(), self::text($res));
        $this->staffByEmail('new@site.test');
        $this->assertStringContainsString('The account is saved, but no invitation was sent.', self::text($this->send('GET', '/folio/staff')));
    }

    #[RunInSeparateProcess]
    public function test_invite_is_offered_and_sent_while_no_password_is_set(): void
    {
        $this->lifecycleSite();
        $this->account('s-inv', 'inv', [], password: null);
        $this->as('ed');

        $form = (string) $this->send('GET', '/folio/staff/s-inv/edit')->getBody();
        $this->assertStringContainsString('action="/folio/staff/s-inv/_action/invite"', $form);
        $this->assertStringNotContainsString('/_action/reset"', $form);
        $form = (string) $this->send('GET', '/folio/staff/s-help/edit')->getBody();
        $this->assertStringNotContainsString('/_action/invite"', $form);
        $this->assertStringContainsString('action="/folio/staff/s-help/_action/reset"', $form);
        $this->assertStringContainsString('action="/folio/staff/s-help/_action/disable"', $form);

        $res = $this->action('s-inv', 'invite');
        $this->assertSame('/folio/staff/s-inv/edit', $res->getHeaderLine('Location'));
        $this->assertStringContainsString('Invitation sent to inv@site.test.', $this->flash('s-inv'));
        $this->assertSame(1, $this->flush());
        $this->assertSame(['inv@site.test'], $this->mails()[0]->to);

        // An account with a password gets a reset link instead.
        $this->action('s-help', 'invite');
        $this->assertStringContainsString('This account already has a password. Send a reset link instead.', $this->flash('s-help'));
        $this->assertSame(0, $this->flush());
    }

    #[RunInSeparateProcess]
    public function test_send_a_reset_link(): void
    {
        $this->lifecycleSite();
        $this->as('ed');

        $this->assertSame('/folio/staff/s-help/edit', $this->action('s-help', 'reset')->getHeaderLine('Location'));
        $this->assertStringContainsString('Reset link sent to help@site.test.', $this->flash('s-help'));
        $this->assertSame(1, $this->flush());
        $this->assertSame(['help@site.test'], $this->mails()[0]->to);

        $this->useSession([]);
        $this->assertSame('/folio', $this->setPassword($this->linkToken())->getHeaderLine('Location'));
        $this->assertSame(200, $this->get('/folio/page')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_disable_ends_the_session_the_remember_cookie_and_the_links(): void
    {
        $this->lifecycleSite();
        $login = $this->signIn('help@site.test', remember: true);
        preg_match('~folio_remember=([^;]+);~', $login->getHeaderLine('Set-Cookie') . ';' . implode(';', $login->getHeader('Set-Cookie')), $m);
        $cookie = $m[1] ?? $this->fail('no remember cookie');
        $this->assertSame(200, $this->get('/folio/page')->getStatusCode());
        $helper = $this->useSession([]);

        $this->as('ed');
        $this->action('s-help', 'reset');
        $this->flush();
        $reset = $this->linkToken();
        $invite = $this->container()->get(Tokens::class)->issue(StaffPasswords::INVITE, 'staff:s-help', 3600);

        $this->assertSame('/folio/staff/s-help/edit', $this->action('s-help', 'disable')->getHeaderLine('Location'));
        $this->assertStringContainsString('Account disabled.', $this->flash('s-help'));
        $stored = $this->stored('staff', 's-help');
        $this->assertFalse(\Preflow\Rbac\StaffManagers::active($stored?->get('active')));
        $this->assertSame('1', (string) $stored?->get('auth_epoch'));

        $editor = $this->useSession($helper);
        $this->assertSame('/folio/login', $this->get('/folio/page')->getHeaderLine('Location'), 'the live session ended');
        $this->useSession([]);
        $this->assertSame('/folio/login', $this->get('/folio/page', ['folio_remember' => $cookie])->getHeaderLine('Location'), 'the remember cookie is dead');
        $this->assertNull($this->container()->get(RememberMe::class)->resolve('session', $cookie));

        // Enabled again, the links sent before stay void.
        $this->useSession($editor);
        $this->action('s-help', 'enable');
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($this->stored('staff', 's-help')?->get('active')));
        $this->useSession([]);
        $this->assertSame(410, $this->setPassword($reset)->getStatusCode());
        $this->assertSame(410, $this->setPassword($invite)->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_unticking_active_in_the_form_disables_alike(): void
    {
        $this->lifecycleSite();
        $login = $this->signIn('help@site.test', remember: true);
        preg_match('~folio_remember=([^;]+);~', implode(';', $login->getHeader('Set-Cookie')), $m);
        $cookie = $m[1] ?? $this->fail('no remember cookie');
        $this->useSession([]);
        $this->as('ed');
        $invite = $this->container()->get(Tokens::class)->issue(StaffPasswords::INVITE, 'staff:s-help', 3600);

        $res = $this->send('POST', '/folio/staff/s-help', ['email' => 'help@site.test', 'name' => 'Help', 'roles' => ['', 'helper'], 'active' => '0']);
        $this->assertSame(302, $res->getStatusCode(), self::text($res));
        $this->assertSame('1', (string) $this->stored('staff', 's-help')?->get('auth_epoch'));
        $this->assertNull($this->container()->get(RememberMe::class)->resolve('session', $cookie));

        // A later save of the disabled account (or enabling it) bumps nothing more and keeps the links void.
        $this->send('POST', '/folio/staff/s-help', ['email' => 'help@site.test', 'name' => 'Help', 'roles' => ['', 'helper'], 'active' => '1']);
        $this->assertSame('1', (string) $this->stored('staff', 's-help')?->get('auth_epoch'));
        $this->useSession([]);
        $this->assertSame(410, $this->setPassword($invite)->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_enable(): void
    {
        $this->lifecycleSite();
        $this->account('s-off', 'off', ['helper'], active: false);
        $this->as('ed');

        $form = (string) $this->send('GET', '/folio/staff/s-off/edit')->getBody();
        $this->assertStringContainsString('action="/folio/staff/s-off/_action/enable"', $form);
        $this->assertStringNotContainsString('/_action/disable"', $form);

        $this->assertSame('/folio/staff/s-off/edit', $this->action('s-off', 'enable')->getHeaderLine('Location'));
        $this->assertStringContainsString('Account enabled.', $this->flash('s-off'));
        $stored = $this->stored('staff', 's-off');
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($stored?->get('active')));
        $this->assertSame('0', (string) $stored?->get('auth_epoch'), 'enabling changes nothing else');

        $this->useSession([]);
        $this->assertSame('/folio', $this->signIn('off@site.test')->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_disable_and_enable_change_only_active_and_the_epoch_of_the_record_as_stored_now(): void
    {
        $this->lifecycleSite();
        $admin = $this->container()->get(\Preflow\Rbac\Types\StaffAdmin::class);
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/staff/s-help/_action/disable');
        $stale = $this->stored('staff', 's-help'); // read when the action's request began
        $meanwhile = $this->stored('staff', 's-help');
        $meanwhile->set('roles', []); // a role removed by another request
        $meanwhile->set('auth_epoch', '3'); // and a password changed
        $this->container()->get(DataManager::class)->saveType($meanwhile, false);

        $admin->disable($stale, $request);

        $stored = $this->stored('staff', 's-help');
        $this->assertFalse(\Preflow\Rbac\StaffManagers::active($stored?->get('active')));
        $this->assertSame([], \Preflow\Rbac\StaffManagers::account($stored)['roles'], 'the removed role stays removed');
        $this->assertSame('4', (string) $stored?->get('auth_epoch'), 'the epoch goes up from the stored one');

        $stale = $this->stored('staff', 's-help');
        $meanwhile = $this->stored('staff', 's-help');
        $meanwhile->set('name', 'Renamed');
        $this->container()->get(DataManager::class)->saveType($meanwhile, false);

        $admin->enable($stale, $request);

        $stored = $this->stored('staff', 's-help');
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($stored?->get('active')));
        $this->assertSame('Renamed', $stored?->get('name'), 'the stale copy did not write back');
        $this->assertSame('4', (string) $stored?->get('auth_epoch'));
    }

    #[RunInSeparateProcess]
    public function test_without_app_url_no_sign_in_link_is_sent_and_the_flash_says_why(): void
    {
        $this->lifecycleSite(url: false);
        $this->as('ed');

        $this->action('s-help', 'reset');
        $this->assertStringContainsString('Set app.url to send sign-in links.', $this->flash('s-help'));
        $res = $this->send('POST', '/folio/staff', ['email' => 'new@site.test', 'name' => 'Newbie', 'roles' => [''], 'active' => '1']);
        $this->assertSame(302, $res->getStatusCode(), self::text($res));
        $this->assertStringContainsString('Set app.url to send sign-in links.', self::text($this->send('GET', '/folio/staff')));
        $this->flush();
        $this->assertSame([], $this->mails(), 'no http://localhost link goes out');
    }

    #[RunInSeparateProcess]
    public function test_every_action_on_an_account_above_you_is_refused(): void
    {
        $this->lifecycleSite(twoManagers: true);
        $this->as('ed');

        $form = (string) $this->send('GET', '/folio/staff/s-mgr/edit')->getBody();
        $this->assertStringNotContainsString('/_action/', $form, 'no buttons for an account above you');
        $this->assertStringContainsString(self::ACCOUNT, html_entity_decode($form, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        foreach (['invite', 'reset', 'disable', 'enable'] as $action) {
            $res = $this->action('s-mgr', $action); // crafted: the form offers none of them
            $this->assertSame('/folio/staff/s-mgr/edit', $res->getHeaderLine('Location'), $action);
            $this->assertStringContainsString(self::ACCOUNT, $this->flash('s-mgr'), $action);
        }
        $mgr = $this->stored('staff', 's-mgr');
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($mgr?->get('active')));
        $this->assertSame('0', (string) $mgr?->get('auth_epoch'));
        $this->assertSame(0, $this->flush());

        // Without staff.edit, no action at all.
        $this->useSession([]);
        $this->as('help');
        $this->assertSame(403, $this->action('s-none', 'reset')->getStatusCode());
        $this->assertSame(403, $this->action('s-none', 'disable')->getStatusCode());
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($this->stored('staff', 's-none')?->get('active')));
        $this->assertSame(0, $this->flush());
    }

    #[RunInSeparateProcess]
    public function test_disabling_the_last_manager_is_refused(): void
    {
        $this->lifecycleSite();
        $this->as('boss');

        $this->assertSame('/folio/staff/s-boss/edit', $this->action('s-boss', 'disable')->getHeaderLine('Location'));
        $this->assertStringContainsString(self::LAST, $this->flash('s-boss'));
        $boss = $this->stored('staff', 's-boss');
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($boss?->get('active')));
        $this->assertSame('0', (string) $boss?->get('auth_epoch'));
        $this->assertSame(200, $this->get('/folio')->getStatusCode(), 'still signed in');

        // With a second manager, either one may go.
        $this->account('s-mgr', 'mgr', ['manager']);
        $this->action('s-mgr', 'disable');
        $this->assertFalse(\Preflow\Rbac\StaffManagers::active($this->stored('staff', 's-mgr')?->get('active')));
        $this->action('s-boss', 'disable');
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($this->stored('staff', 's-boss')?->get('active')), 'boss is the last one again');
    }

    #[RunInSeparateProcess]
    public function test_changing_your_own_password_needs_the_current_one_and_ends_other_sessions(): void
    {
        $this->lifecycleSite();
        $this->signIn('help@site.test');
        $other = $this->useSession([]);
        $this->signIn('help@site.test');

        $page = (string) $this->get('/folio/_account')->getBody();
        $this->assertStringContainsString('help@site.test', $page);
        $this->assertStringContainsString('name="current"', $page);
        $this->assertStringContainsString('action="/folio/_account/password"', $page);

        $crafted = ['email' => 'evil@site.test', 'name' => 'Evil', 'roles' => ['', 'admin'], 'active' => '0', 'auth_epoch' => '7', 'password_hash' => 'x'];
        $wrong = $this->send('POST', '/folio/_account/password', ['current' => 'not it', 'password' => self::NEW_PASSWORD, 'repeat' => self::NEW_PASSWORD] + $crafted);
        $this->assertSame(422, $wrong->getStatusCode());
        $this->assertStringContainsString('The current password is wrong.', self::text($wrong));
        $short = $this->send('POST', '/folio/_account/password', ['current' => 'secret', 'password' => 'short', 'repeat' => 'short']);
        $this->assertSame(422, $short->getStatusCode());
        $this->assertStringContainsString('at least 10 characters', self::text($short));
        $mismatch = $this->send('POST', '/folio/_account/password', ['current' => 'secret', 'password' => self::NEW_PASSWORD, 'repeat' => 'something else here']);
        $this->assertSame(422, $mismatch->getStatusCode());
        $this->assertSame('0', (string) $this->stored('staff', 's-help')?->get('auth_epoch'));

        $res = $this->send('POST', '/folio/_account/password', ['current' => 'secret', 'password' => self::NEW_PASSWORD, 'repeat' => self::NEW_PASSWORD] + $crafted);
        $this->assertSame('/folio/_account', $res->getHeaderLine('Location'));
        $this->assertStringContainsString('Password changed.', self::text($this->get('/folio/_account')));

        $stored = $this->stored('staff', 's-help');
        $this->assertSame('help@site.test', $stored?->get('email'));
        $this->assertSame('Help', $stored?->get('name'));
        $this->assertSame(['helper'], \Preflow\Rbac\RoleList::normalize($stored?->get('roles')));
        $this->assertTrue(\Preflow\Rbac\StaffManagers::active($stored?->get('active')));
        $this->assertSame('1', (string) $stored?->get('auth_epoch'));

        $this->assertSame(200, $this->get('/folio/page')->getStatusCode(), 'this browser stays signed in');
        $this->useSession($other);
        $this->assertSame('/folio/login', $this->get('/folio/page')->getHeaderLine('Location'), 'the other session ended');
        $this->useSession([]);
        $this->assertSame('/folio/login', $this->signIn('help@site.test')->getHeaderLine('Location'), 'the old password is dead');
        $this->assertSame('/folio', $this->signIn('help@site.test', self::NEW_PASSWORD)->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_the_own_password_change_is_throttled(): void
    {
        $this->lifecycleSite();
        $this->signIn('help@site.test');
        for ($i = 0; $i < 10; ++$i) {
            $this->send('POST', '/folio/_account/password', ['current' => 'wrong ' . $i, 'password' => self::NEW_PASSWORD, 'repeat' => self::NEW_PASSWORD]);
        }

        $res = $this->send('POST', '/folio/_account/password', ['current' => 'secret', 'password' => self::NEW_PASSWORD, 'repeat' => self::NEW_PASSWORD]);

        $this->assertSame(429, $res->getStatusCode());
        $this->assertSame('0', (string) $this->stored('staff', 's-help')?->get('auth_epoch'));
    }

    #[RunInSeparateProcess]
    public function test_the_account_page_needs_no_permission_and_is_in_the_user_menu(): void
    {
        $this->lifecycleSite();
        $this->signIn('none@site.test'); // no roles at all

        $this->assertSame(200, $this->get('/folio/_account')->getStatusCode());
        $this->assertStringContainsString('href="/folio/_account"', (string) $this->get('/folio/_account')->getBody());
        $this->assertStringContainsString('href="/folio/_account"', (string) $this->get('/folio/page')->getBody(), 'the menu on the no-access page too');

        // The config user has no account record: no page, no link.
        $this->useSession([]);
        $this->as('admin');
        $this->assertSame(404, $this->get('/folio/_account')->getStatusCode());
        $this->assertStringNotContainsString('/folio/_account', (string) $this->get('/folio/page')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_site_without_the_staff_model_is_unchanged(): void
    {
        $this->install();
        $this->as('admin');

        $this->assertSame(404, $this->get('/folio/_account')->getStatusCode());
        $this->assertSame(404, $this->send('POST', '/folio/_account/password', ['current' => 'secret', 'password' => self::NEW_PASSWORD, 'repeat' => self::NEW_PASSWORD])->getStatusCode());
        $this->assertStringNotContainsString('_account', (string) $this->get('/folio/page')->getBody());
        $this->assertSame(404, $this->send('POST', '/folio/staff/x/_action/invite')->getStatusCode());
    }
}
