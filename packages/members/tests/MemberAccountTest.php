<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Members\Members;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MemberFlows;

/** The account page: change the password, delete the account; who may see it. */
final class MemberAccountTest extends TestCase
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
    public function test_account_requires_member(): void
    {
        $anon = $this->send('GET', '/members/account');
        $this->assertSame(303, $anon->getStatusCode());
        $this->assertSame('/members/login?next=/members/account', $anon->getHeaderLine('Location'));
        $this->assertSame('/de/members/login?next=/de/members/account', $this->send('GET', '/de/members/account')->getHeaderLine('Location'));
        $this->assertSame(303, $this->post('/members/account/password', ['current' => 'x', 'password' => 'y', 'repeat' => 'y'])->getStatusCode());
        $this->assertSame(303, $this->post('/members/account/delete', ['current' => self::PASSWORD, 'confirm' => '1'])->getStatusCode());

        $this->makeMember();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $page = $this->send('GET', '/members/account');
        $this->assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        $this->assertNotNull(Dom::first($html, '//form[@action="/members/account/password"]//input[@name="current"]'));
        $this->assertNotNull(Dom::first($html, '//form[@action="/members/account/delete"]//input[@name="confirm"]'));
        $this->assertStringContainsString('ada@example.com', $html);
        $this->assertNotNull(Dom::first($html, '//a[@href="/members"]'), 'back to the landing');
    }

    #[RunInSeparateProcess]
    public function test_staff_is_sent_to_the_members_home(): void
    {
        $this->signIn();

        $response = $this->send('GET', '/members/account');

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/members', $response->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_change_password_requires_current_and_rotates_remember(): void
    {
        $member = $this->makeMember();
        $id = (string) $member->getId();
        $oldCookie = $this->cookieValue($this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]), 'site_member');
        $remember = $this->flow->container()->get(RememberMe::class);
        $this->assertNotNull($remember->resolve('members', $oldCookie));

        $wrong = $this->post('/members/account/password', ['current' => 'not my password', 'password' => 'a brand new password', 'repeat' => 'a brand new password']);
        $this->assertSame(422, $wrong->getStatusCode());
        $this->assertStringContainsString('current password is wrong', (string) $wrong->getBody());
        $short = $this->post('/members/account/password', ['current' => self::PASSWORD, 'password' => 'short', 'repeat' => 'short']);
        $this->assertSame(422, $short->getStatusCode());
        $mismatch = $this->post('/members/account/password', ['current' => self::PASSWORD, 'password' => 'a brand new password', 'repeat' => 'another new password']);
        $this->assertSame(422, $mismatch->getStatusCode());
        $this->assertNotNull($remember->resolve('members', $oldCookie), 'nothing changed yet');

        $ok = $this->post('/members/account/password', ['current' => self::PASSWORD, 'password' => 'a brand new password', 'repeat' => 'a brand new password']);

        $this->assertSame(303, $ok->getStatusCode());
        $this->assertSame('/members/account', $ok->getHeaderLine('Location'));
        $this->assertNotNull($this->cookie($ok, 'site_member'), 'a fresh remember cookie');
        $this->assertNull($remember->resolve('members', $oldCookie), 'the old remember cookie no longer restores');
        $this->assertStringContainsString('Password changed.', $this->html('/members/account'));
        $this->assertStringNotContainsString('Password changed.', $this->html('/members/account'), 'the notice shows once');
        $this->post('/members/logout', []);
        $this->assertSame(422, $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])->getStatusCode());
        $this->assertSame(303, $this->post('/members/login', ['email' => 'ada@example.com', 'password' => 'a brand new password'])->getStatusCode());
        $this->assertSame($id, (string) $this->member('ada@example.com')->getId());
    }

    #[RunInSeparateProcess]
    public function test_password_change_signs_out_a_second_session(): void
    {
        $this->makeMember();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $first = $this->useSession([]); // a second browser
        $second = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $secondCookie = $this->cookieValue($second, 'site_member');
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'the second browser is in');

        $secondSession = $this->useSession($first);
        $ok = $this->post('/members/account/password', ['current' => self::PASSWORD, 'password' => 'a brand new password', 'repeat' => 'a brand new password']);
        $this->assertSame(303, $ok->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'the changing session stays signed in');
        $this->assertSame('1', (string) $this->member('ada@example.com')->get('auth_epoch'));

        $this->useSession($secondSession);
        $out = $this->send('GET', '/members/account', cookies: ['site_member' => $secondCookie]);
        $this->assertSame(303, $out->getStatusCode(), 'the second session is signed out, its remember cookie too');
        $this->assertSame('/members/login?next=/members/account', $out->getHeaderLine('Location'));
        $this->assertStringContainsString('site_member=; Max-Age=0', (string) $this->cookie($out, 'site_member'));
    }

    #[RunInSeparateProcess]
    public function test_wrong_current_password_is_throttled(): void
    {
        $this->makeMember();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(422, $this->post('/members/account/password', ['current' => 'nope nope nope', 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getStatusCode(), "try {$i}");
        }

        $blocked = $this->post('/members/account/password', ['current' => self::PASSWORD, 'password' => 'a brand new password', 'repeat' => 'a brand new password']);
        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertSame(429, $this->post('/members/account/delete', ['current' => self::PASSWORD, 'confirm' => '1'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_delete_requires_password_and_removes_record_and_session(): void
    {
        $member = $this->makeMember();
        $id = (string) $member->getId();
        $cookie = $this->cookieValue($this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]), 'site_member');
        $tokens = $this->flow->container()->get(Tokens::class);
        $confirm = $tokens->issue('members.confirm', $id, 3600);
        $reset = $tokens->issue('members.reset', $id, 3600);

        $this->assertSame(422, $this->post('/members/account/delete', ['current' => 'not my password', 'confirm' => '1'])->getStatusCode());
        $noBox = $this->post('/members/account/delete', ['current' => self::PASSWORD]);
        $this->assertSame(422, $noBox->getStatusCode());
        $this->assertStringContainsString('understand', (string) $noBox->getBody());
        $this->assertNotNull($this->flow->container()->get(Members::class)->find($id), 'still there');

        $ok = $this->post('/members/account/delete', ['current' => self::PASSWORD, 'confirm' => '1']);

        $this->assertSame(303, $ok->getStatusCode());
        $this->assertSame('/members/register', $ok->getHeaderLine('Location'));
        $this->assertSame('private, no-store', $ok->getHeaderLine('Cache-Control'));
        $this->assertNull($this->flow->container()->get(Members::class)->find($id));
        $this->assertNull($this->flow->container()->get(Members::class)->findByEmail('ada@example.com'));
        $this->assertNull($this->flow->container()->get(RememberMe::class)->resolve('members', $cookie));
        $this->assertNull($tokens->consume($confirm, 'members.confirm'));
        $this->assertNull($tokens->consume($reset, 'members.reset'));
        $register = $this->html('/members/register');
        $this->assertStringContainsString('Your account is deleted.', $register, 'the register page shows the notice once');
        $this->assertStringNotContainsString('Your account is deleted.', $this->html('/members/register'));
        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_account_pages_no_store(): void
    {
        $this->makeMember();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $responses = [
            $this->send('GET', '/members/account'),
            $this->post('/members/account/password', ['current' => 'wrong wrong wrong', 'password' => 'a brand new password', 'repeat' => 'a brand new password']),
            $this->post('/members/account/password', ['current' => self::PASSWORD, 'password' => 'a brand new password', 'repeat' => 'a brand new password']),
            $this->post('/members/account/delete', ['current' => 'wrong wrong wrong']),
        ];
        $this->post('/members/logout', []);
        $responses[] = $this->send('GET', '/members/account');
        $responses[] = $this->post('/members/account/delete', ['current' => 'x']);

        foreach ($responses as $i => $response) {
            $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'), "response {$i}");
        }
    }

    #[RunInSeparateProcess]
    public function test_german_account_posts_to_german_urls(): void
    {
        $this->makeMember('ada@example.com', 'member', 'de');
        $this->post('/de/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $html = $this->html('/de/members/account');
        $this->assertNotNull(Dom::first($html, '//form[@action="/de/members/account/password"]'));
        $ok = $this->post('/de/members/account/password', ['current' => self::PASSWORD, 'password' => 'ein ganz neues Passwort', 'repeat' => 'ein ganz neues Passwort']);
        $this->assertSame('/de/members/account', $ok->getHeaderLine('Location'));
        $this->assertStringContainsString('Passwort geändert.', $this->html('/de/members/account'));
        $del = $this->post('/de/members/account/delete', ['current' => 'ein ganz neues Passwort', 'confirm' => '1']);
        $this->assertSame('/de/members/register', $del->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_declined_member_with_a_remember_cookie_loses_access(): void
    {
        $m = $this->makeMember();
        $cookie = $this->flow->container()->get(RememberMe::class)->issue('members', (string) $m->getId());
        $this->assertSame(200, $this->send('GET', '/members/account', cookies: ['site_member' => $cookie])->getStatusCode(), 'the remember cookie opens the account');

        $this->signIn();
        $page = $this->admin('/folio/member/' . $m->getId() . '/edit');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $t);
        $this->flow->handle($this->request('POST', '/folio/member/' . $m->getId() . '/_action/decline', ['message' => 'Sorry.', '_csrf_token' => $t[1] ?? '']));
        unset($_SESSION);
        $this->flow = $this->app(); // a fresh request: only the cookie

        $this->assertSame(303, $this->send('GET', '/members/account', cookies: ['site_member' => $cookie])->getStatusCode(), 'a declined member is out');
    }

    #[RunInSeparateProcess]
    public function test_a_declined_member_with_an_active_session_loses_access(): void
    {
        $m = $this->makeMember();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode());

        // Declined without revoking anything: only the member's roles change.
        $members = $this->flow->container()->get(Members::class);
        $members->decide($members->find((string) $m->getId()) ?? $this->fail('gone'), 'rejected');

        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode(), 'roles are read on every request');
    }
}
