<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;
use Preflow\Members\SignIn;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Notify\Notifier;

/** A locked member signs in nowhere (login, reset, confirm, SignIn::in()); every sign-in notes last_login_at. */
final class LockedSignInTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->flowSite();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_locked_member_login_fails_like_a_wrong_password(): void
    {
        $m = $this->makeMember();
        $wrong = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => 'not the password']);
        $this->flow->container()->get(Members::class)->lock($m);

        $locked = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(422, $locked->getStatusCode());
        $this->assertSame(422, $wrong->getStatusCode());
        $error = '//*[contains(@class,"error") or @role="alert"]';
        $a = Dom::first((string) $wrong->getBody(), $error);
        $b = Dom::first((string) $locked->getBody(), $error);
        $this->assertNotNull($b);
        $this->assertSame(trim((string) $a?->textContent), trim($b->textContent));
        $this->assertNull($this->cookie($locked, 'site_member'));
        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode(), 'not signed in');
        $throttle = $this->flow->container()->get(\Preflow\Core\Throttle\Throttle::class);
        [, $window] = $this->flow->container()->get(MembersConfig::class)->throttle('login');
        $this->assertTrue($throttle->tooMany(MembersConfig::THROTTLE_LOGIN, hash('sha256', 'ada@example.com'), 2, $window), 'the wrong and the locked attempt both counted');
        $this->assertSame('', (string) $this->member('ada@example.com')->get('last_login_at'));
    }

    /** @return array<string, array{string}> */
    public static function notAdmittedStates(): array
    {
        return ['unconfirmed' => ['unconfirmed'], 'waiting' => ['pending']];
    }

    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('notAdmittedStates')]
    public function test_locked_unconfirmed_or_waiting_member_gets_the_wrong_password_refusal(string $state): void
    {
        $m = $this->makeMember('ada@example.com', $state);
        $wrong = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => 'not the password']);
        $this->flow->container()->get(Members::class)->lock($m);

        $locked = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(422, $wrong->getStatusCode());
        $this->assertSame(422, $locked->getStatusCode());
        $error = '//*[contains(@class,"error") or @role="alert"]';
        $a = Dom::first((string) $wrong->getBody(), $error);
        $b = Dom::first((string) $locked->getBody(), $error);
        $this->assertNotNull($b);
        $this->assertSame(trim((string) $a?->textContent), trim($b->textContent), 'they never learn their state');
        $this->assertNull($this->cookie($locked, 'site_member'));
    }

    #[RunInSeparateProcess]
    public function test_locked_member_gets_no_reset_mail(): void
    {
        $m = $this->makeMember();
        $this->flow->container()->get(Members::class)->lock($m);

        $response = $this->post('/members/forgot', ['email' => 'ada@example.com']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->flow->container()->get(Notifier::class)->flushDeferred());
        $this->assertSame([], $this->mails());
    }

    #[RunInSeparateProcess]
    public function test_resend_sends_no_mail_to_a_locked_unconfirmed_member(): void
    {
        $m = $this->makeMember('ada@example.com', 'unconfirmed');
        $other = $this->post('/members/resend', ['email' => 'nobody@example.com']);
        $this->flow->container()->get(Members::class)->lock($m);

        $response = $this->post('/members/resend', ['email' => 'ada@example.com']);

        $this->assertSame($other->getStatusCode(), $response->getStatusCode());
        $this->assertSame([], $this->mails());
    }

    #[RunInSeparateProcess]
    public function test_resend_still_mails_an_unlocked_unconfirmed_member(): void
    {
        $this->makeMember('ada@example.com', 'unconfirmed');

        $this->post('/members/resend', ['email' => 'ada@example.com']);

        $this->assertCount(1, $this->mails());
    }

    /** @return array<string, array{string}> */
    public static function lockedKinds(): array
    {
        return ['unconfirmed' => ['unconfirmed'], 'member' => ['member']];
    }

    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('lockedKinds')]
    public function test_registering_again_sends_no_mail_to_a_locked_account(string $state): void
    {
        $m = $this->makeMember('ada@example.com', $state);
        $this->flow->container()->get(Members::class)->lock($m);

        $response = $this->post('/members/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->mails());
    }

    #[RunInSeparateProcess]
    public function test_reset_link_of_a_locked_member_is_expired(): void
    {
        $m = $this->makeMember();
        $token = $this->flow->container()->get(Tokens::class)->issue(MembersConfig::RESET_PURPOSE, (string) $m->getId(), 3600);
        $hash = (string) $m->get('password_hash');
        $this->flow->container()->get(Members::class)->lock($m);

        $response = $this->post('/members/reset', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password']);

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame($hash, (string) $this->member('ada@example.com')->get('password_hash'));
        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode(), 'not signed in');
    }

    #[RunInSeparateProcess]
    public function test_confirm_link_of_a_locked_unconfirmed_member_is_used(): void
    {
        $m = $this->makeMember('ada@example.com', 'unconfirmed');
        $token = $this->flow->container()->get(Tokens::class)->issue(MembersConfig::CONFIRM_PURPOSE, (string) $m->getId(), 3600);
        $this->flow->container()->get(Members::class)->lock($m);

        $response = $this->post('/members/confirm', ['token' => $token]);

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame('', (string) $this->member('ada@example.com')->get('email_confirmed_at'));
    }

    #[RunInSeparateProcess]
    public function test_unlocked_member_signs_in_with_the_old_password(): void
    {
        $m = $this->makeMember();
        $members = $this->flow->container()->get(Members::class);
        $members->lock($m);
        $members->unlock($m);

        $response = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'signed in');
    }

    #[RunInSeparateProcess]
    public function test_login_notes_last_login_at(): void
    {
        $this->makeMember();
        $this->assertSame('', (string) $this->member('ada@example.com')->get('last_login_at'));

        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->assertNotSame('', (string) $this->member('ada@example.com')->get('last_login_at'));
    }

    #[RunInSeparateProcess]
    public function test_login_with_an_old_hash_keeps_the_new_hash_and_notes_last_login_at(): void
    {
        $member = $this->makeMember();
        $member->set('password_hash', crypt(self::PASSWORD, '$1$saltsalt$'));
        $this->flow->container()->get(\Preflow\Data\DataManager::class)->saveType($member, false);

        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $stored = $this->member('ada@example.com');
        $this->assertStringStartsNotWith('$1$', (string) $stored->get('password_hash'));
        $this->assertNotSame('', (string) $stored->get('last_login_at'));
    }

    #[RunInSeparateProcess]
    public function test_reset_notes_last_login_at(): void
    {
        $m = $this->makeMember();
        $token = $this->flow->container()->get(Tokens::class)->issue(MembersConfig::RESET_PURPOSE, (string) $m->getId(), 3600);

        $this->assertSame(303, $this->post('/members/reset', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password'])->getStatusCode());

        $this->assertNotSame('', (string) $this->member('ada@example.com')->get('last_login_at'));
    }

    #[RunInSeparateProcess]
    public function test_confirm_notes_last_login_at(): void
    {
        $m = $this->makeMember('ada@example.com', 'unconfirmed');
        $token = $this->flow->container()->get(Tokens::class)->issue(MembersConfig::CONFIRM_PURPOSE, (string) $m->getId(), 3600);

        $response = $this->post('/members/confirm', ['token' => $token]);

        $this->assertSame(303, $response->getStatusCode(), 'open admission signs the member in');
        $this->assertNotSame('', (string) $this->member('ada@example.com')->get('last_login_at'));
    }

    #[RunInSeparateProcess]
    public function test_sign_in_refuses_a_locked_member(): void
    {
        $m = $this->makeMember();
        $this->flow->container()->get(Members::class)->lock($m);
        $request = (new Psr17Factory())->createServerRequest('GET', '/');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('locked');
        $this->flow->container()->get(SignIn::class)->in($m, $request);
    }
}
