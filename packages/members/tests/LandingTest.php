<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;

/** GET {path}: the members' default landing, unless the site has its own page there. */
final class LandingTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_signed_in_shows_the_landing_with_account_and_sign_out(): void
    {
        $this->flowSite();
        $this->makeMember();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $response = $this->send('GET', '/members');
        $html = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('Hello, Ada Lovelace', $html);
        $this->assertNotNull(Dom::first($html, '//a[@href="/members/account"]'));
        $this->assertNotNull(Dom::first($html, '//form[@action="/members/logout"]//input[@name="_csrf_token"]'));
    }

    #[RunInSeparateProcess]
    public function test_the_landing_speaks_the_page_language(): void
    {
        $this->flowSite();
        $this->makeMember(lang: 'de');
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $html = html_entity_decode($this->html('/de/members'), ENT_QUOTES);
        $this->assertStringContainsString('Willkommen, Ada Lovelace', $html);
        $this->assertNotNull(Dom::first($html, '//a[@href="/de/members/account"]'));
    }

    #[RunInSeparateProcess]
    public function test_signed_out_goes_to_the_login(): void
    {
        $this->flowSite();

        $response = $this->send('GET', '/members');
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/members/login', $response->getHeaderLine('Location'));
        $this->assertSame('/de/members/login', $this->send('GET', '/de/members')->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_waiting_member_is_not_let_in(): void
    {
        $this->flowSite();
        $this->makeMember(state: 'pending');

        $this->assertSame(303, $this->send('GET', '/members')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_a_site_route_on_the_same_path_wins(): void
    {
        $this->flowSite(files: ['app/pages/members/index.twig' => 'SITE MEMBERS PAGE']);

        $this->assertSame('SITE MEMBERS PAGE', trim($this->html('/members')));
        $this->assertSame('SITE MEMBERS PAGE', trim($this->html('/de/members')));
        $this->assertSame(200, $this->send('GET', '/members/login')->getStatusCode(), 'the other member pages stay');
    }

    #[RunInSeparateProcess]
    public function test_the_landing_follows_the_configured_path(): void
    {
        $this->flowSite(['path' => '/konto']);

        $this->assertSame('/konto/login', $this->send('GET', '/konto')->getHeaderLine('Location'));
        $this->assertSame(404, $this->send('GET', '/members')->getStatusCode());
    }
}
