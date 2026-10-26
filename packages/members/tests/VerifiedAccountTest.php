<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthManager;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Members\Admission\OwnerApproval;
use Preflow\Members\MemberRoles;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;
use Preflow\Members\SignIn;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Notify\Notifier;
use Preflow\Testing\ArraySession;
use Psr\Http\Message\ServerRequestInterface;

/** Accounts a project creates with an address it has proven itself, and signing them in. */
final class VerifiedAccountTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_a_verified_account_is_confirmed_and_admitted_without_a_mail(): void
    {
        $this->flowSite();
        $members = $this->flow->container()->get(Members::class);

        $m = $members->register('Ada@Example.com', 'Ada Lovelace', self::PASSWORD, 'en', emailVerified: true);
        $this->flow->container()->get(Notifier::class)->flushDeferred();

        $this->assertSame([], $this->mails(), 'no confirmation mail');
        $this->assertSame([], $this->confirmTokens());
        $stored = $this->member('ada@example.com');
        $this->assertNotSame('', (string) $stored->get('email_confirmed_at'));
        $this->assertSame(['member'], MemberRoles::of($stored));
        $this->assertSame((string) $m->getId(), (string) $stored->getId());
    }

    #[RunInSeparateProcess]
    public function test_the_admission_still_decides_a_verified_account(): void
    {
        $this->flowSite(['admission' => OwnerApproval::class, 'notice_email' => 'owner@site.test']);
        $members = $this->flow->container()->get(Members::class);

        $members->register('ada@example.com', 'Ada Lovelace', self::PASSWORD, 'en', emailVerified: true);
        $this->flow->container()->get(Notifier::class)->flushDeferred();

        $stored = $this->member('ada@example.com');
        $this->assertNotSame('', (string) $stored->get('email_confirmed_at'));
        $this->assertSame(['pending'], MemberRoles::of($stored));
        $mails = $this->mails();
        $this->assertCount(1, $mails, 'the owner hears of it, as after a confirmation');
        $this->assertSame(['owner@site.test'], $mails[0]->to);
    }

    #[RunInSeparateProcess]
    public function test_without_verified_the_account_waits_for_its_confirmation(): void
    {
        $this->flowSite();
        $m = $this->flow->container()->get(Members::class)->register('ada@example.com', 'Ada Lovelace', self::PASSWORD, 'en');

        $this->assertSame('', (string) $m->get('email_confirmed_at'));
        $this->assertSame([], MemberRoles::of($m));
    }

    #[RunInSeparateProcess]
    public function test_sign_in_signs_a_fresh_account_in_with_remember(): void
    {
        $this->flowSite();
        $c = $this->flow->container();
        $m = $c->get(Members::class)->register('ada@example.com', 'Ada Lovelace', self::PASSWORD, 'en', emailVerified: true);
        $request = $this->sessionRequest();

        $c->get(SignIn::class)->in($m, $request);

        $guard = $c->get(AuthManager::class)->guard(MembersConfig::GUARD);
        $this->assertSame((string) $m->getId(), (string) $guard->user($request)?->getAuthId());
        $this->assertNotSame([], $guard->pullCookies(), 'remember on');
    }

    #[RunInSeparateProcess]
    public function test_sign_in_refuses_an_account_that_is_not_admitted(): void
    {
        $this->flowSite(['admission' => OwnerApproval::class]);
        $c = $this->flow->container();
        $m = $c->get(Members::class)->register('ada@example.com', 'Ada Lovelace', self::PASSWORD, 'en', emailVerified: true);

        $this->expectException(\LogicException::class);
        $c->get(SignIn::class)->in($m, $this->sessionRequest());
    }

    private function sessionRequest(): ServerRequestInterface
    {
        return $this->request('GET', '/games/catan')->withAttribute(SessionInterface::class, new ArraySession());
    }

    /** @return list<array<string, mixed>> */
    private function confirmTokens(): array
    {
        return $this->flow->container()->get(DataManager::class)->raw("SELECT id FROM auth_tokens WHERE purpose = 'members.confirm'", [], 'auth');
    }
}
