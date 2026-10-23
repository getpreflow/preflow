<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Viewer;
use Preflow\Notify\Notifier;
use Psr\Http\Message\ResponseInterface;

/** Login with the 30-day remember cookie, logout, forgot and reset password, resend the confirmation. */
final class MemberLoginTest extends TestCase
{
    use MemberFlows;

    protected function setUp(): void
    {
        $this->flowSite();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_member_logs_in_and_gets_remember_cookie(): void
    {
        $this->makeMember();

        $response = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/members', $response->getHeaderLine('Location'));
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertMatchesRegularExpression('~^site_member=[^;]+; Max-Age=2592000; Path=/; HttpOnly~', (string) $this->cookie($response, 'site_member'));
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'signed in');
    }

    #[RunInSeparateProcess]
    public function test_login_is_case_insensitive_on_email(): void
    {
        $this->makeMember();

        $this->assertSame(303, $this->post('/members/login', ['email' => ' ADA@Example.com ', 'password' => self::PASSWORD])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_wrong_password_unknown_email_and_declined_give_identical_body(): void
    {
        $this->makeMember();
        $this->makeMember('bob@example.com', 'rejected');
        $bodies = [];
        foreach ([['ada@example.com', 'wrong password!'], ['nobody@example.com', self::PASSWORD], ['bob@example.com', self::PASSWORD]] as [$email, $password]) {
            $response = $this->post('/members/login', ['email' => $email, 'password' => $password]);
            $this->assertSame(422, $response->getStatusCode(), $email);
            $bodies[] = $this->stripVolatile((string) $response->getBody(), $email);
        }

        $this->assertStringContainsString('Email or password is wrong.', $bodies[0]);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame($bodies[0], $bodies[2]);
    }

    #[RunInSeparateProcess]
    public function test_an_unknown_address_costs_a_hash_like_a_wrong_password(): void
    {
        $this->makeMember();
        $spy = new class extends \stdClass implements PasswordHasherInterface {
            public int $hashes = 0;
            public int $verifies = 0;
            public function hash(string $password): string { $this->hashes++; return (new NativePasswordHasher())->hash($password); }
            public function verify(string $password, string $hash): bool { $this->verifies++; return (new NativePasswordHasher())->verify($password, $hash); }
            public function needsRehash(string $hash): bool { return false; }
        };
        $this->flow->container()->instance(PasswordHasherInterface::class, $spy);

        $this->post('/members/login', ['email' => 'nobody@example.com', 'password' => 'whatever it is']);
        $this->assertSame([1, 0], [$spy->hashes, $spy->verifies], 'unknown: one hash');
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => 'whatever it is']);
        $this->assertSame([1, 1], [$spy->hashes, $spy->verifies], 'known: one verify');
    }

    #[RunInSeparateProcess]
    public function test_unconfirmed_with_correct_password_offers_resend(): void
    {
        $this->makeMember('ada@example.com', 'unconfirmed');

        $response = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Please confirm your email first', $html);
        $this->assertSame('ada@example.com', Dom::first($html, '//form[@action="/members/resend"]//input[@name="email"]')?->getAttribute('value'));
        $this->assertNull($this->cookie($response, 'site_member'));
    }

    #[RunInSeparateProcess]
    public function test_waiting_with_correct_password_sees_thanks(): void
    {
        $this->makeMember('ada@example.com', 'pending');

        $response = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Thanks for your interest', (string) $response->getBody());
        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode(), 'not signed in');
    }

    #[RunInSeparateProcess]
    public function test_login_throttled_after_10_failures_per_email(): void
    {
        $this->makeMember();
        for ($i = 1; $i <= 10; $i++) {
            $this->remoteAddr = '198.51.100.' . $i; // a different client each time: only the email counts
            $this->assertSame(422, $this->post('/members/login', ['email' => 'ada@example.com', 'password' => 'nope nope nope'])->getStatusCode(), "try {$i}");
        }
        $this->remoteAddr = '192.0.2.200';

        $response = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('Too many attempts', (string) $response->getBody());
    }

    #[RunInSeparateProcess]
    public function test_login_throttled_per_client_and_cleared_by_success(): void
    {
        $this->makeMember();
        for ($i = 1; $i <= 9; $i++) {
            $this->post('/members/login', ['email' => "x{$i}@example.com", 'password' => 'nope nope nope']);
        }
        $this->assertSame(303, $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])->getStatusCode());
        $this->post('/members/logout', []);
        for ($i = 1; $i <= 9; $i++) {
            $this->post('/members/login', ['email' => "y{$i}@example.com", 'password' => 'nope nope nope']);
        }
        $this->assertSame(422, $this->post('/members/login', ['email' => 'z@example.com', 'password' => 'nope nope nope'])->getStatusCode(), 'the success cleared the client count');
        $this->assertSame(429, $this->post('/members/login', ['email' => 'z2@example.com', 'password' => 'nope nope nope'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_login_redirects_to_a_safe_next_only(): void
    {
        $this->makeMember();
        $response = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD, 'next' => '/members/r/force-map']);
        $this->assertSame('/members/r/force-map', $response->getHeaderLine('Location'));
        $this->post('/members/logout', []);

        $response = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD, 'next' => '//evil.test']);
        $this->assertSame('/members', $response->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_login_form_carries_next_csrf_and_redirects_signed_in_members(): void
    {
        $this->makeMember();
        $html = $this->html('/members/login?next=' . rawurlencode('/members/r/x'));
        $form = '//form[@action="/members/login"]';

        $this->assertSame('/members/r/x', Dom::first($html, $form . '//input[@name="next"]')?->getAttribute('value'));
        $this->assertNotNull(Dom::first($html, $form . '//input[@name="_csrf_token"]'));
        $this->assertNotNull(Dom::first($html, '//a[@href="/members/forgot"]'));
        $this->assertNotNull(Dom::first($html, '//a[@href="/members/register"]'));
        $this->assertNotNull(Dom::first($this->html('/de/members/login'), '//form[@action="/de/members/login"]'));

        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $again = $this->send('GET', '/members/login?next=' . rawurlencode('/members/r/x'));
        $this->assertSame(303, $again->getStatusCode());
        $this->assertSame('/members/r/x', $again->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_logout_keeps_staff_session(): void
    {
        $this->makeMember();
        $this->signIn();
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'staff is in');
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $response = $this->post('/members/logout', []);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/members', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('site_member=; Max-Age=0', $this->cookie($response, 'site_member') ?? '');
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'staff stays signed in');
        $this->assertNull($this->flow->container()->get(Viewer::class)->member());
    }

    #[RunInSeparateProcess]
    public function test_forgot_answers_the_same_for_unknown_and_member(): void
    {
        $this->makeMember();
        $known = $this->post('/members/forgot', ['email' => 'Ada@example.com']);
        $unknown = $this->post('/members/forgot', ['email' => 'nobody@example.com']);

        $this->assertSame(200, $known->getStatusCode());
        $this->assertSame(200, $unknown->getStatusCode());
        $this->assertStringContainsString('If an account exists for this address, a link is on its way.', (string) $known->getBody());
        $this->assertSame($this->stripVolatile((string) $known->getBody(), 'ada@example.com'), $this->stripVolatile((string) $unknown->getBody(), 'nobody@example.com'));
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame(['ada@example.com'], $mails[0]->to);
        $this->assertMatchesRegularExpression('~https://site\.test/members/reset/[A-Za-z0-9_-]+~', $mails[0]->text);
    }

    #[RunInSeparateProcess]
    public function test_forgot_returns_before_any_mail_is_sent_for_a_member_and_for_an_unknown_address(): void
    {
        $this->makeMember();
        $this->holdTheFlush();

        $known = $this->post('/members/forgot', ['email' => 'ada@example.com']);
        $unknown = $this->post('/members/forgot', ['email' => 'nobody@example.com']);

        $this->assertSame(200, $known->getStatusCode());
        $this->assertSame(200, $unknown->getStatusCode());
        $this->assertSame([], $this->mails(), 'both answers are out before a single mail goes');
        $this->assertSame(1, $this->flow->container()->get(Notifier::class)->flushDeferred());
        $this->assertSame(['ada@example.com'], $this->mails()[0]->to);
    }

    #[RunInSeparateProcess]
    public function test_resend_sends_its_mail_after_the_response(): void
    {
        $this->makeMember('u@example.com', 'unconfirmed');
        $this->holdTheFlush();

        $response = $this->post('/members/resend', ['email' => 'u@example.com']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->mails());
        $this->assertSame(1, $this->flow->container()->get(Notifier::class)->flushDeferred());
        $this->assertSame(['u@example.com'], $this->mails()[0]->to);
    }

    #[RunInSeparateProcess]
    public function test_forgot_sends_nothing_for_unconfirmed_or_waiting_and_is_throttled(): void
    {
        $this->makeMember('u@example.com', 'unconfirmed');
        $this->makeMember('w@example.com', 'pending');
        $this->post('/members/forgot', ['email' => 'u@example.com']);
        $this->post('/members/forgot', ['email' => 'w@example.com']);
        $this->assertSame([], $this->mails());

        for ($i = 3; $i <= 5; $i++) {
            $this->assertSame(200, $this->post('/members/forgot', ['email' => "n{$i}@example.com"])->getStatusCode());
        }
        $this->assertSame(429, $this->post('/members/forgot', ['email' => 'n6@example.com'])->getStatusCode(), 'per client');
    }

    #[RunInSeparateProcess]
    public function test_reset_form_does_not_consume_the_token(): void
    {
        $this->makeMember();
        $token = $this->resetToken();

        foreach ([1, 2] as $visit) {
            $html = $this->html('/members/reset/' . $token);
            $this->assertSame($token, Dom::first($html, '//form[@action="/members/reset"]//input[@name="token"]')?->getAttribute('value'), "visit {$visit}");
            $this->assertNotNull(Dom::first($html, '//form[@action="/members/reset"]//input[@name="repeat"]'));
        }
        $this->assertSame(303, $this->resetWith($token, self::PASSWORD . '!')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_reset_sets_password_logs_in_and_revokes_remember(): void
    {
        $member = $this->makeMember();
        $id = (string) $member->getId();
        $oldCookie = $this->flow->container()->get(RememberMe::class)->issue('members', $id);
        $token = $this->resetToken();
        $other = $this->flow->container()->get(Tokens::class)->issue('members.reset', $id, 3600);

        $response = $this->resetWith($token, 'a brand new password');

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/members', $response->getHeaderLine('Location'));
        $this->assertNotNull($this->cookie($response, 'site_member'), 'a fresh remember cookie');
        $this->assertNull($this->flow->container()->get(RememberMe::class)->resolve('members', $oldCookie), 'older sessions are gone');
        $this->assertNull($this->flow->container()->get(Tokens::class)->consume($other, 'members.reset'), 'other reset links are void');
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'signed in');
        $this->post('/members/logout', []);
        $this->assertSame(422, $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])->getStatusCode(), 'old password is dead');
        $this->assertSame(303, $this->post('/members/login', ['email' => 'ada@example.com', 'password' => 'a brand new password'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_reset_ends_the_members_other_sessions(): void
    {
        $this->makeMember();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $other = $this->useSession([]); // the browser that resets the password has nobody signed in
        $token = $this->resetToken();

        $this->assertSame(303, $this->resetWith($token, 'a brand new password')->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'the resetting browser is signed in');

        $this->useSession($other);
        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode(), 'the other browser is signed out');
    }

    #[RunInSeparateProcess]
    public function test_reset_validates_password_without_using_the_token(): void
    {
        $this->makeMember();
        $token = $this->resetToken();

        $short = $this->resetWith($token, 'short');
        $this->assertSame(422, $short->getStatusCode());
        $this->assertStringContainsString('at least 10 characters', (string) $short->getBody());
        $mismatch = $this->post('/members/reset', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'something else here']);
        $this->assertSame(422, $mismatch->getStatusCode());
        $this->assertSame($token, Dom::first((string) $mismatch->getBody(), '//input[@name="token"]')?->getAttribute('value'));

        $this->assertSame(303, $this->resetWith($token, 'a brand new password')->getStatusCode(), 'the token still works');
    }

    #[RunInSeparateProcess]
    public function test_reset_token_single_use(): void
    {
        $this->makeMember();
        $token = $this->resetToken();
        $this->assertSame(303, $this->resetWith($token, 'a brand new password')->getStatusCode());

        $second = $this->resetWith($token, 'yet another password');

        $this->assertSame(410, $second->getStatusCode());
        $this->assertStringContainsString('This link was already used or has expired', (string) $second->getBody());
        $this->assertSame(410, $this->resetWith('garbage', 'yet another password')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_resend_sends_only_for_unconfirmed_and_is_throttled_3_per_hour(): void
    {
        $this->makeMember('ada@example.com', 'unconfirmed');
        $this->makeMember('bob@example.com', 'member');
        $tokens = $this->flow->container()->get(Tokens::class);
        $old = $tokens->issue('members.confirm', (string) $this->member('ada@example.com')->getId(), 3600);

        $fresh = $this->post('/members/resend', ['email' => 'ADA@example.com']);
        $other = $this->post('/members/resend', ['email' => 'bob@example.com']);
        $unknown = $this->post('/members/resend', ['email' => 'nobody@example.com']);

        $this->assertSame(200, $fresh->getStatusCode());
        $this->assertStringContainsString('Check your inbox', (string) $fresh->getBody());
        $this->assertSame($this->stripVolatile((string) $fresh->getBody(), 'ada@example.com'), $this->stripVolatile((string) $unknown->getBody(), 'nobody@example.com'));
        $this->assertSame(200, $other->getStatusCode());
        $this->assertSame(200, $unknown->getStatusCode());
        $this->assertCount(1, $this->mails());
        $this->assertSame(['ada@example.com'], $this->mails()[0]->to);
        $this->assertStringContainsString('/members/confirm/', $this->mails()[0]->text);
        $this->assertNull($tokens->consume($old, 'members.confirm'), 'the old link is void');

        $this->post('/members/resend', ['email' => 'ada@example.com']);
        $this->post('/members/resend', ['email' => 'ada@example.com']);
        $over = $this->post('/members/resend', ['email' => 'ada@example.com']);
        $this->assertSame(200, $over->getStatusCode());
        $this->assertStringContainsString('Check your inbox', (string) $over->getBody());
        $this->assertCount(3, $this->mails(), 'the fourth request sends nothing');
    }

    #[RunInSeparateProcess]
    public function test_resend_page_without_an_address_goes_to_the_login(): void
    {
        $this->assertSame('/members/login', $this->send('GET', '/members/resend')->getHeaderLine('Location'));
        $this->assertSame('/de/members/login', $this->send('GET', '/de/members/resend')->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_german_pages_post_to_german_urls(): void
    {
        foreach (['login', 'forgot'] as $page) {
            $html = $this->html('/de/members/' . $page);
            $this->assertNotNull(Dom::first($html, '//form[@action="/de/members/' . $page . '"]'), $page);
        }
        $this->assertStringContainsString('/de/members/forgot', $this->html('/de/members/login'));
        $this->makeMember('ada@example.com', 'member', 'de');
        $this->assertSame('/de/members', $this->post('/de/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_login_lands_on_the_configured_landing(): void
    {
        $this->removeScaffold();
        $this->flowSite(['landing' => '/members/start']);
        $this->makeMember();

        $this->assertSame('/members/start', $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])->getHeaderLine('Location'));
        $this->assertSame('/members', $this->post('/members/logout', [])->getHeaderLine('Location'), 'logout goes to the members home');
    }

    // --- helpers -------------------------------------------------------------------------------

    private function resetToken(): string
    {
        $before = count($this->mails());
        $this->post('/members/forgot', ['email' => 'ada@example.com']);
        $this->assertCount($before + 1, $this->mails(), 'a reset mail');

        return $this->linkToken($before, 'reset');
    }

    private function resetWith(string $token, string $password): ResponseInterface
    {
        return $this->post('/members/reset', ['token' => $token, 'password' => $password, 'repeat' => $password]);
    }
}
