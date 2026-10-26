<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Data\DataManager;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Folio\Users\StaffPasswords;
use Preflow\Notify\Notifier;

/**
 * The Folio login with record users (staff): throttled, enumeration-resistant, remember-me when
 * the guard has it, last sign-in stamped; forgot password and the set-password page for reset
 * and invite links.
 */
final class FolioStaffLoginTest extends TestCase
{
    use StaffScaffold;

    protected function setUp(): void
    {
        $this->staffSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    #[RunInSeparateProcess]
    public function test_a_staff_record_signs_in_and_its_last_login_is_stamped(): void
    {
        $this->makeStaff();

        $response = $this->post('/folio/login', ['email' => ' ADA@site.test ', 'password' => self::PASSWORD]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/folio', $response->getHeaderLine('Location'));
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'signed in');
        $stamp = (string) $this->staff('ada@site.test')->get('last_login_at');
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $stamp), $stamp);
        $this->assertNull($this->cookie($response, 'folio_remember'), 'no remember cookie unless asked');
    }

    #[RunInSeparateProcess]
    public function test_the_config_user_still_signs_in_through_the_chain(): void
    {
        $response = $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame('/folio', $response->getHeaderLine('Location'));
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_an_md5_crypt_hash_is_upgraded_at_sign_in_and_the_session_survives(): void
    {
        $old = crypt(self::PASSWORD, '$1$saltsalt$');
        $this->assertStringStartsWith('$1$', $old);
        $this->makeStaff(data: ['password_hash' => $old, 'auth_epoch' => '5']);

        $response = $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD]);

        $this->assertSame('/folio', $response->getHeaderLine('Location'));
        $staff = $this->staff('ada@site.test');
        $new = (string) $staff->get('password_hash');
        $hasher = new NativePasswordHasher();
        $this->assertNotSame($old, $new);
        $this->assertFalse($hasher->needsRehash($new), 'a hash needsRehash() accepts');
        $this->assertTrue($hasher->verify(self::PASSWORD, $new));
        $this->assertSame('5', (string) $staff->get('auth_epoch'), 'the epoch is untouched');
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'the new session survives');
    }

    #[RunInSeparateProcess]
    public function test_a_config_user_signs_in_and_nothing_changes(): void
    {
        $auth = (string) file_get_contents($this->dir . '/config/auth.php');

        $this->assertSame('/folio', $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD])->getHeaderLine('Location'));

        $this->assertSame($auth, (string) file_get_contents($this->dir . '/config/auth.php'));
        $this->assertSame([], $this->dm()->queryType('staff')->get()->items(), 'no record made');
    }

    #[RunInSeparateProcess]
    public function test_config_era_sessions_and_remember_cookies_end_once_the_record_exists(): void
    {
        $login = $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD, 'remember' => '1']);
        $cookie = (string) preg_replace('~^folio_remember=([^;]+);.*$~', '$1', (string) $this->cookie($login, 'folio_remember'));
        $this->assertNotSame('', $cookie);
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'the config user is signed in');

        // The import: the config admin becomes a record, which is then disabled.
        $this->makeStaff(self::CONFIG_EMAIL, active: false);

        $this->assertSame(302, $this->send('GET', '/folio')->getStatusCode(), 'the config-era session ends');
        $this->useSession([]);
        $this->assertSame(302, $this->send('GET', '/folio', cookies: ['folio_remember' => $cookie])->getStatusCode(), 'the config-era remember cookie ends');
        $this->assertSame('/folio/login', $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD])->getHeaderLine('Location'), 'the disabled record decides');
    }

    #[RunInSeparateProcess]
    public function test_after_the_import_the_person_signs_in_again_as_the_record(): void
    {
        $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD]);
        $this->makeStaff(self::CONFIG_EMAIL);

        $this->assertSame(302, $this->send('GET', '/folio')->getStatusCode(), 'signed out, active record or not');
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD])->getHeaderLine('Location'));
        $this->assertSame(200, $this->send('GET', '/folio/_account')->getStatusCode(), 'the record, not the config user (which has no account page)');
    }

    #[RunInSeparateProcess]
    public function test_remember_me_is_offered_and_sets_the_cookie(): void
    {
        $this->makeStaff();
        $page = $this->html('/folio/login');
        $this->assertMatchesRegularExpression('~<input type="checkbox" name="remember" value="1"~', $page);
        $this->assertStringContainsString('href="/folio/forgot"', $page);

        $response = $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD, 'remember' => '1']);

        $this->assertMatchesRegularExpression('~^folio_remember=[^;]+; Max-Age=2592000; Path=/folio; HttpOnly~', (string) $this->cookie($response, 'folio_remember'));
    }

    #[RunInSeparateProcess]
    public function test_no_remember_checkbox_when_the_guard_has_no_remember(): void
    {
        $this->removeSite();
        $this->staffSite(guard: []);
        $this->makeStaff();

        $this->assertStringNotContainsString('name="remember"', $this->html('/folio/login'));
        $response = $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD, 'remember' => '1']);
        $this->assertSame('/folio', $response->getHeaderLine('Location'));
        $this->assertNull($this->cookie($response, 'remember_session'));
        $this->assertNull($this->cookie($response, 'folio_remember'));
    }

    #[RunInSeparateProcess]
    public function test_unknown_config_inactive_and_wrong_password_answer_alike(): void
    {
        $this->makeStaff();
        $this->makeStaff('off@site.test', active: false);
        $pages = [];
        foreach ([
            'unknown' => ['nobody@site.test', self::PASSWORD],
            'config wrong password' => [self::CONFIG_EMAIL, 'wrong password!'],
            'inactive' => ['off@site.test', self::PASSWORD],
            'record wrong password' => ['ada@site.test', 'wrong password!'],
        ] as $case => [$email, $password]) {
            $response = $this->post('/folio/login', ['email' => $email, 'password' => $password]);
            $this->assertSame(302, $response->getStatusCode(), $case);
            $this->assertSame('/folio/login', $response->getHeaderLine('Location'), $case);
            $pages[$case] = $this->stable($this->html('/folio/login'));
            $this->assertStringContainsString('Invalid email or password.', $pages[$case], $case);
        }

        $this->assertCount(1, array_unique($pages), 'one page for every refusal');
        $this->assertSame(302, $this->send('GET', '/folio')->getStatusCode(), 'nobody signed in');
    }

    #[RunInSeparateProcess]
    public function test_an_unknown_address_costs_a_hash_like_a_wrong_password(): void
    {
        $this->makeStaff();
        $spy = new class implements PasswordHasherInterface {
            public int $hashes = 0;
            public int $verifies = 0;
            public function hash(string $password): string { $this->hashes++; return (new NativePasswordHasher())->hash($password); }
            public function verify(string $password, string $hash): bool { $this->verifies++; return (new NativePasswordHasher())->verify($password, $hash); }
            public function needsRehash(string $hash): bool { return false; }
        };
        $this->flow->container()->instance(PasswordHasherInterface::class, $spy);

        $this->post('/folio/login', ['email' => 'nobody@site.test', 'password' => 'whatever it is']);
        $this->assertSame([1, 0], [$spy->hashes, $spy->verifies], 'unknown: one hash');
        $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => 'whatever it is']);
        $this->assertSame([1, 1], [$spy->hashes, $spy->verifies], 'known: one verify');
        $this->makeStaff('invited@site.test', password: null);
        $this->assertSame('/folio/login', $this->post('/folio/login', ['email' => 'invited@site.test', 'password' => ''])->getHeaderLine('Location'));
        $this->assertSame([2, 1], [$spy->hashes, $spy->verifies], 'no password yet: one hash, as for an unknown address');
    }

    #[RunInSeparateProcess]
    public function test_login_is_throttled_per_email(): void
    {
        $this->makeStaff();
        for ($i = 1; $i <= 10; $i++) {
            $this->remoteAddr = '198.51.100.' . $i; // a different client each time: only the address counts
            $this->assertSame(302, $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => 'nope nope nope'])->getStatusCode(), "try {$i}");
        }
        $this->remoteAddr = '192.0.2.200';

        $response = $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD]);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('Too many attempts', (string) $response->getBody());
        $this->assertSame(302, $this->send('GET', '/folio')->getStatusCode(), 'not signed in');
    }

    #[RunInSeparateProcess]
    public function test_login_is_throttled_per_client_and_a_success_clears_only_its_address(): void
    {
        $this->makeStaff();
        for ($i = 1; $i <= 9; $i++) {
            $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => 'nope nope nope']);
        }
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'));
        $this->post('/folio/logout', []);
        $this->useSession([]);

        $this->remoteAddr = '198.51.100.99';
        for ($i = 1; $i <= 9; $i++) {
            $this->assertSame(302, $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => 'nope nope nope'])->getStatusCode(), "the success cleared the address count, try {$i}");
        }
        $this->remoteAddr = '203.0.113.7';
        $this->assertSame(302, $this->post('/folio/login', ['email' => 'z@site.test', 'password' => 'nope nope nope'])->getStatusCode(), 'the client\'s tenth');
        $this->assertSame(429, $this->post('/folio/login', ['email' => 'z2@site.test', 'password' => 'nope nope nope'])->getStatusCode(), 'the success did not clear the client count');
    }

    #[RunInSeparateProcess]
    public function test_forgot_answers_alike_and_mails_only_an_active_staff_record(): void
    {
        $this->makeStaff();
        $this->makeStaff('off@site.test', active: false);
        $this->assertMatchesRegularExpression('~<form[^>]+method="post"[^>]+action="/folio/forgot"~', $this->html('/folio/forgot'));

        $pages = [];
        foreach (['Ada@site.test', 'nobody@site.test', self::CONFIG_EMAIL, 'off@site.test'] as $email) {
            $response = $this->post('/folio/forgot', ['email' => $email]);
            $this->assertSame(200, $response->getStatusCode(), $email);
            $pages[$email] = $this->stable((string) $response->getBody());
        }

        $this->assertStringContainsString('If an account exists for this address, a link is on its way.', $pages['nobody@site.test']);
        $this->assertCount(1, array_unique($pages), 'one answer for every address');
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame(['ada@site.test'], $mails[0]->to);
        $this->assertStringContainsString('Reset your Folio password', $mails[0]->subject);
        $this->assertStringContainsString('one hour', $mails[0]->text);
        $this->linkToken();
    }

    #[RunInSeparateProcess]
    public function test_forgot_answers_before_the_mail_goes_and_is_throttled(): void
    {
        $this->makeStaff();
        (new \ReflectionProperty(\Preflow\Core\Application::class, 'emitting'))->setValue($this->flow, true); // as run(): mail after the response

        $this->assertSame(200, $this->post('/folio/forgot', ['email' => 'ada@site.test'])->getStatusCode());
        $this->assertSame([], $this->mails(), 'the answer is out before the mail');
        $this->assertSame(1, $this->flow->container()->get(Notifier::class)->flushDeferred());

        for ($i = 2; $i <= 5; $i++) {
            $this->assertSame(200, $this->post('/folio/forgot', ['email' => "n{$i}@site.test"])->getStatusCode());
        }
        $this->assertSame(429, $this->post('/folio/forgot', ['email' => 'n6@site.test'])->getStatusCode(), 'per client');
    }

    #[RunInSeparateProcess]
    public function test_the_password_page_does_not_use_up_the_link_and_a_post_uses_it_once(): void
    {
        $this->makeStaff();
        $this->post('/folio/forgot', ['email' => 'ada@site.test']);
        $token = $this->linkToken();

        foreach ([1, 2] as $visit) {
            $page = $this->html('/folio/password/' . $token);
            $this->assertMatchesRegularExpression('~<form[^>]+action="/folio/password"~', $page, "visit {$visit}");
            $this->assertStringContainsString('name="token" value="' . $token . '"', $page, "visit {$visit}");
        }

        $first = $this->post('/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password']);
        $this->assertSame(302, $first->getStatusCode());
        $this->assertSame('/folio', $first->getHeaderLine('Location'));
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'signed in');

        $again = $this->post('/folio/password', ['token' => $token, 'password' => 'yet another password', 'repeat' => 'yet another password']);
        $this->assertSame(410, $again->getStatusCode(), 'used once');
        $this->assertStringContainsString('This link was already used or has expired', (string) $again->getBody());
        $crafted = $this->post('/folio/password', ['token' => 'crafted-' . $token, 'password' => 'yet another password', 'repeat' => 'yet another password']);
        $this->assertSame(410, $crafted->getStatusCode());
        $this->assertSame($this->stable((string) $again->getBody()), $this->stable((string) $crafted->getBody()));
    }

    #[RunInSeparateProcess]
    public function test_a_new_password_bumps_the_epoch_and_ends_other_sessions_remember_rows_and_links(): void
    {
        $ada = $this->makeStaff();
        $id = (string) $ada->getId();
        $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD]);
        $other = $this->useSession([]); // the browser that sets the password has nobody signed in
        $remember = $this->flow->container()->get(RememberMe::class)->issue('session', $id);
        $tokens = $this->flow->container()->get(Tokens::class);
        $invite = $tokens->issue(StaffPasswords::INVITE, 'staff:' . $id, 3600);
        $this->post('/folio/forgot', ['email' => 'ada@site.test']);
        $this->post('/folio/forgot', ['email' => 'ada@site.test']);
        $token = $this->linkToken(0);
        $second = $this->linkToken(1);

        $response = $this->post('/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password']);

        $this->assertSame('/folio', $response->getHeaderLine('Location'));
        $this->assertSame('1', (string) $this->staff('ada@site.test')->get('auth_epoch'));
        $this->assertNull($this->flow->container()->get(RememberMe::class)->resolve('session', $remember), 'remember-me rows are gone');
        $this->assertNull($tokens->consume($invite, StaffPasswords::INVITE), 'the invite link is void');
        $this->assertNull($tokens->consume($second, StaffPasswords::RESET), 'the other reset link is void');
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'this browser is signed in');
        $this->useSession($other);
        $this->assertSame(302, $this->send('GET', '/folio')->getStatusCode(), 'the other browser is signed out');
        $this->useSession([]);
        $this->assertSame('/folio/login', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'), 'the old password is dead');
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => 'a brand new password'])->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_short_or_mismatched_password_keeps_the_link(): void
    {
        $this->makeStaff();
        $this->post('/folio/forgot', ['email' => 'ada@site.test']);
        $token = $this->linkToken();

        $short = $this->post('/folio/password', ['token' => $token, 'password' => 'short', 'repeat' => 'short']);
        $this->assertSame(422, $short->getStatusCode());
        $this->assertStringContainsString('at least 10 characters', (string) $short->getBody());
        $this->assertStringContainsString('name="token" value="' . $token . '"', (string) $short->getBody());
        $mismatch = $this->post('/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'something else here']);
        $this->assertSame(422, $mismatch->getStatusCode());

        $this->assertSame('/folio', $this->post('/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_password_min_comes_from_the_folio_config(): void
    {
        $this->removeSite();
        $this->staffSite(folio: ['password_min' => 16]);
        $this->makeStaff();
        $this->post('/folio/forgot', ['email' => 'ada@site.test']);

        $response = $this->post('/folio/password', ['token' => $this->linkToken(), 'password' => 'fifteen chars!!', 'repeat' => 'fifteen chars!!']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('at least 16 characters', (string) $response->getBody());
    }

    #[RunInSeparateProcess]
    public function test_an_expired_link_is_refused(): void
    {
        $ada = $this->makeStaff();
        $past = new Tokens($this->dm(), 'auth', static fn (): int => time() - 2 * 3600);
        $token = $past->issue(StaffPasswords::RESET, 'staff:' . $ada->getId(), 3600);

        $response = $this->post('/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password']);

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame(302, $this->send('GET', '/folio')->getStatusCode(), 'not signed in');
    }

    #[RunInSeparateProcess]
    public function test_a_link_of_a_disabled_account_is_refused(): void
    {
        $ada = $this->makeStaff();
        $this->post('/folio/forgot', ['email' => 'ada@site.test']);
        $token = $this->linkToken();
        $ada->set('active', false);
        $this->dm()->saveType($ada, false);

        $this->assertSame(410, $this->post('/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_an_invite_mails_a_week_long_link_that_sets_the_first_password(): void
    {
        $bob = $this->makeStaff('bob@site.test', password: null, data: ['name' => 'Bob']);
        $passwords = $this->flow->container()->get(StaffPasswords::class);
        $passwords->invite($bob);
        $passwords->invite($bob); // a second invite voids the first link
        $this->flow->container()->get(Notifier::class)->flushDeferred();

        $mails = $this->mails();
        $this->assertCount(2, $mails);
        $this->assertSame(['bob@site.test'], $mails[1]->to);
        $this->assertStringContainsString('Staff Site', $mails[1]->subject);
        $this->assertStringContainsString('Hi Bob', $mails[1]->text);
        $this->assertStringContainsString('7 days', $mails[1]->text);
        $this->assertSame('/folio/login', $this->post('/folio/login', ['email' => 'bob@site.test', 'password' => ''])->getHeaderLine('Location'), 'no sign-in before a password is set');
        $this->assertSame(410, $this->post('/folio/password', ['token' => $this->linkToken(0), 'password' => 'bobs first password', 'repeat' => 'bobs first password'])->getStatusCode());

        $response = $this->post('/folio/password', ['token' => $this->linkToken(1), 'password' => 'bobs first password', 'repeat' => 'bobs first password']);

        $this->assertSame('/folio', $response->getHeaderLine('Location'));
        $this->post('/folio/logout', []);
        $this->useSession([]);
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'bob@site.test', 'password' => 'bobs first password'])->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_link_for_another_purpose_or_user_type_is_refused(): void
    {
        $ada = $this->makeStaff();
        $tokens = $this->flow->container()->get(Tokens::class);
        $members = $tokens->issue('members.reset', 'staff:' . $ada->getId(), 3600);
        $unknownType = $tokens->issue(StaffPasswords::RESET, 'member:' . $ada->getId(), 3600);
        $noType = $tokens->issue(StaffPasswords::RESET, (string) $ada->getId(), 3600);

        foreach (['other purpose' => $members, 'other type' => $unknownType, 'bare id' => $noType] as $case => $token) {
            $this->assertSame(410, $this->post('/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getStatusCode(), $case);
        }
    }

    #[RunInSeparateProcess]
    public function test_set_password_is_throttled_per_client(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(410, $this->post('/folio/password', ['token' => "guess-{$i}", 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getStatusCode(), "try {$i}");
        }

        $this->assertSame(429, $this->post('/folio/password', ['token' => 'guess-11', 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_mails_are_in_the_sites_default_language(): void
    {
        $this->removeSite();
        $this->staffSite(locale: 'de');
        $this->makeStaff();

        $this->post('/folio/forgot', ['email' => 'ada@site.test']);

        $this->assertStringContainsString('Folio-Passwort', $this->mails()[0]->subject);
        $this->assertStringContainsString('eine Stunde', $this->mails()[0]->text);
        $this->assertStringContainsString('Sign in', $this->html('/folio/login'), 'the admin pages stay English');
    }

    #[RunInSeparateProcess]
    public function test_the_last_login_stamp_is_best_effort(): void
    {
        $this->makeStaff();
        $this->dm()->execute('DROP TABLE "staff"', [], 'sqlite'); // the record cannot be saved (nor found)
        $this->makeStaffTableWithoutStamp();
        $this->makeStaff();

        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'));
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'signed in all the same');
        $this->assertStringContainsString('folio: last sign-in not saved for account staff:', (string) @file_get_contents($this->dir . '/error.log'));
    }

    #[RunInSeparateProcess]
    public function test_the_own_account_page_changes_only_the_password(): void
    {
        $ada = $this->makeStaff();
        $id = (string) $ada->getId();
        $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD]);
        $remember = $this->flow->container()->get(RememberMe::class)->issue('session', $id);
        $invite = $this->flow->container()->get(Tokens::class)->issue(StaffPasswords::INVITE, 'staff:' . $id, 3600);

        $page = $this->html('/folio/_account');
        $this->assertStringContainsString('ada@site.test', $page);
        $this->assertStringContainsString('href="/folio/_account"', $page, 'the user menu links here');
        $this->assertSame(422, $this->post('/folio/_account/password', ['current' => 'nope', 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getStatusCode());

        $response = $this->post('/folio/_account/password', ['current' => self::PASSWORD, 'password' => 'a brand new password', 'repeat' => 'a brand new password', 'email' => 'eve@site.test', 'roles' => 'root']);

        $this->assertSame('/folio/_account', $response->getHeaderLine('Location'));
        $stored = $this->staff('ada@site.test');
        $this->assertSame('admin', $stored->get('roles'));
        $this->assertSame('1', (string) $stored->get('auth_epoch'));
        $this->assertNull($this->flow->container()->get(RememberMe::class)->resolve('session', $remember));
        $this->assertNull($this->flow->container()->get(Tokens::class)->consume($invite, StaffPasswords::INVITE));
        $this->assertSame(200, $this->send('GET', '/folio/_account')->getStatusCode(), 'still signed in');

        // The config user has no account page.
        $this->post('/folio/logout', []);
        $this->useSession([]);
        $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD]);
        $this->assertSame(404, $this->send('GET', '/folio/_account')->getStatusCode());
        $this->assertStringNotContainsString('/folio/_account', $this->html('/folio'));
    }

    #[RunInSeparateProcess]
    public function test_a_password_change_racing_a_disable_keeps_the_account_disabled(): void
    {
        $ada = $this->makeStaff(); // read before the disable, as a request that started earlier holds it
        $disabled = $this->staff('ada@site.test');
        $disabled->set('active', false);
        $disabled->set('auth_epoch', '1');
        $this->dm()->saveType($disabled, false);

        $saved = $this->flow->container()->get(StaffPasswords::class)->setPassword($ada, 'a brand new password');

        $this->assertFalse($saved, 'refused: the account is inactive now');
        $stored = $this->staff('ada@site.test');
        $this->assertFalse((bool) $stored->get('active'), 'stays disabled');
        $this->assertSame('1', (string) $stored->get('auth_epoch'));
        $this->assertTrue(password_verify(self::PASSWORD, (string) $stored->get('password_hash')), 'the password is unchanged');
        $this->assertSame('/folio/login', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => 'a brand new password'])->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_new_password_is_saved_on_the_record_as_stored_now(): void
    {
        $ada = $this->makeStaff();
        $changed = $this->staff('ada@site.test');
        $changed->set('roles', 'editor');
        $this->dm()->saveType($changed, false);

        $this->assertTrue($this->flow->container()->get(StaffPasswords::class)->setPassword($ada, 'a brand new password'));

        $stored = $this->staff('ada@site.test');
        $this->assertSame('editor', $stored->get('roles'), 'the stale copy did not write its roles back');
        $this->assertSame('1', (string) $stored->get('auth_epoch'));
        $this->assertTrue(password_verify('a brand new password', (string) $stored->get('password_hash')));
    }

    #[RunInSeparateProcess]
    public function test_the_last_login_stamp_does_not_write_back_a_removed_role(): void
    {
        $this->makeStaff();
        $passwords = $this->flow->container()->get(StaffPasswords::class);
        $user = $passwords->account($this->staff('ada@site.test')); // read at sign-in
        $this->assertNotNull($user);
        $demoted = $this->staff('ada@site.test');
        $demoted->set('roles', '');
        $demoted->set('active', false);
        $this->dm()->saveType($demoted, false);

        $passwords->stampLogin($user);

        $stored = $this->staff('ada@site.test');
        $this->assertSame('', (string) $stored->get('roles'), 'the role stays removed');
        $this->assertFalse((bool) $stored->get('active'), 'the account stays disabled');
        $this->assertNotSame('', (string) $stored->get('last_login_at'), 'stamped all the same');
    }

    #[RunInSeparateProcess]
    public function test_without_app_url_forgot_mails_no_link_logs_why_and_answers_alike(): void
    {
        $this->removeSite();
        $this->staffSite(url: null);
        $this->makeStaff();

        $known = $this->post('/folio/forgot', ['email' => 'ada@site.test']);
        $unknown = $this->post('/folio/forgot', ['email' => 'nobody@site.test']);

        $this->assertSame(200, $known->getStatusCode());
        $this->assertSame($this->stable((string) $unknown->getBody()), $this->stable((string) $known->getBody()), 'the generic answer');
        $this->assertSame([], $this->mails(), 'no http://localhost link goes out');
        $log = (string) @file_get_contents($this->dir . '/error.log');
        $this->assertSame(1, substr_count($log, 'folio: app.url is not set'), $log);
        $this->expectException(\Preflow\Folio\Users\MissingAppUrl::class);
        $this->flow->container()->get(StaffPasswords::class)->invite($this->staff('ada@site.test'));
    }

    private function makeStaffTableWithoutStamp(): void
    {
        $this->dm()->execute('CREATE TABLE "staff" ("uuid" TEXT PRIMARY KEY, "email" TEXT, "name" TEXT, "password_hash" TEXT, "roles" TEXT, "active" INTEGER, "auth_epoch" TEXT)', [], 'sqlite');
    }
}
