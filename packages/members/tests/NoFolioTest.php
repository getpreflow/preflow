<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Record\RecordUser;
use Preflow\Members\MemberRoles;
use Preflow\Members\MembersConfig;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;

/**
 * The members core on a site with auth, data, routing, view, notify and i18n, and neither Folio
 * nor rbac: their classes can't be loaded, as when the packages aren't installed.
 */
final class NoFolioTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function setUp(): void
    {
        $loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
        $loader->setPsr4('Preflow\\Folio\\', [sys_get_temp_dir() . '/no-such-folio']);
        $loader->setPsr4('Preflow\\Rbac\\', [sys_get_temp_dir() . '/no-such-rbac']);
        $this->assertFalse(class_exists(\Preflow\Folio\FolioServiceProvider::class));
        $this->assertFalse(class_exists(\Preflow\Rbac\RbacServiceProvider::class));
        $this->flowSite();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** No layout in config/members.php: the package's own. @return array{0: string, 1: ?string} */
    private function siteLayout(): array
    {
        return ['resources/unused.twig', null];
    }

    #[RunInSeparateProcess]
    public function test_register_confirm_sign_out_and_in_forgot_reset_and_account(): void
    {
        $form = $this->html('/members/register');
        $this->assertNotNull(Dom::first($form, '//form[@action="/members/register"]//input[@name="_csrf_token"]'));
        $this->assertNotNull(Dom::first($form, '//body[contains(@class,"members-page")]/main'), 'the package\'s layout');
        $this->assertSame('en', Dom::first($form, '//html')?->getAttribute('lang'));

        $sent = $this->post('/members/register', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertSame(200, $sent->getStatusCode());
        $this->assertStringContainsString('Check your inbox', (string) $sent->getBody());
        $this->assertSame([], MemberRoles::of($this->member('ada@example.com')));

        $token = $this->linkToken(0, 'confirm');
        $this->assertNotNull(Dom::first($this->html('/members/confirm/' . $token), '//form[@action="/members/confirm"]'));
        $in = $this->post('/members/confirm', ['token' => $token]);
        $this->assertSame(303, $in->getStatusCode());
        $this->assertSame('/members', $in->getHeaderLine('Location'), 'admitted at once, signed in, at the landing');
        $this->assertNotNull($this->cookie($in, 'site_member'));
        $this->assertSame(['member'], MemberRoles::of($this->member('ada@example.com')), 'the default admission admits after the confirmation');
        $this->assertCount(1, $this->mails(), 'no owner notice');

        $account = $this->send('GET', '/members/account');
        $this->assertSame(200, $account->getStatusCode());
        $this->assertStringContainsString('ada@example.com', (string) $account->getBody());

        $out = $this->post('/members/logout', []);
        $this->assertSame(303, $out->getStatusCode());
        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode(), 'signed out');

        $this->assertSame(303, $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'signed in again');
        $this->post('/members/logout', []);

        $this->assertStringContainsString('a link is on its way', (string) $this->post('/members/forgot', ['email' => 'ada@example.com'])->getBody());
        $this->flow->container()->get(\Preflow\Notify\Notifier::class)->flushDeferred();
        $reset = $this->post('/members/reset', ['token' => $this->linkToken(1, 'reset'), 'password' => 'a brand new password', 'repeat' => 'a brand new password']);
        $this->assertSame(303, $reset->getStatusCode());
        $this->assertSame('/members', $reset->getHeaderLine('Location'));
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'the reset signs in');
        $this->post('/members/logout', []);
        $this->assertSame(422, $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])->getStatusCode(), 'the old password is gone');
        $this->assertSame(303, $this->post('/members/login', ['email' => 'ada@example.com', 'password' => 'a brand new password'])->getStatusCode());

        $loaded = array_filter(get_declared_classes(), static fn (string $c): bool => str_starts_with($c, 'Preflow\\Folio\\') || str_starts_with($c, 'Preflow\\Rbac\\'));
        $this->assertSame([], array_values($loaded));
    }

    #[RunInSeparateProcess]
    public function test_a_site_without_a_models_directory_gets_the_member_type(): void
    {
        unlink($this->dir . '/config/models/page.json');
        rmdir($this->dir . '/config/models');
        $this->migrate();
        $this->flow = $this->app();

        $sent = $this->post('/members/register', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertSame(200, $sent->getStatusCode());
        $this->assertStringContainsString('Check your inbox', (string) $sent->getBody());
        $in = $this->post('/members/confirm', ['token' => $this->linkToken(0, 'confirm')]);
        $this->assertSame(303, $in->getStatusCode());
        $this->assertSame(['member'], MemberRoles::of($this->member('ada@example.com')));
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode());
        $this->assertFalse(is_dir($this->dir . '/config/models'), 'nothing written to the site');
    }

    /** @return array<string, array{string, bool}> */
    public static function states(): array
    {
        return ['member' => ['member', true], 'pending' => ['pending', false], 'rejected' => ['rejected', false]];
    }

    #[RunInSeparateProcess]
    #[DataProvider('states')]
    public function test_only_an_admitted_member_reaches_what_members_access_gates(string $state, bool $allowed): void
    {
        $m = $this->makeMember($state . '@example.com', $state);
        $cookie = $this->flow->container()->get(\Preflow\Auth\Remember\RememberMe::class)->issue('members', (string) $m->getId());

        $this->assertSame($allowed ? 200 : 303, $this->send('GET', '/members/account', cookies: ['site_member' => $cookie])->getStatusCode(), 'the account page');

        $c = $this->app()->container();
        $c->get(AuthManager::class)->addUser(new RecordUser($this->member($state . '@example.com')), MembersConfig::GUARD);
        $this->assertSame($allowed, $c->get(Access::class)->canAny(MembersConfig::PERMISSION), 'members.access');
        $this->assertFalse($c->get(Access::class)->canAny(MembersConfig::DECIDE), 'nothing else');
    }

    #[RunInSeparateProcess]
    public function test_the_pending_and_rejected_cannot_sign_in(): void
    {
        $this->makeMember('pending@example.com', 'pending');
        $this->makeMember('rejected@example.com', 'rejected');

        $this->assertStringContainsString('Thanks for your interest', (string) $this->post('/members/login', ['email' => 'pending@example.com', 'password' => self::PASSWORD])->getBody());
        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode());
        $this->assertSame(422, $this->post('/members/login', ['email' => 'rejected@example.com', 'password' => self::PASSWORD])->getStatusCode());
        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode());
    }
}
