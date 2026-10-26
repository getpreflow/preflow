<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;

/** What every member route shares: CSRF on each form, a session only where the members live, the routes per locale. */
final class MembersHttpTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    private const array FORMS = ['/register', '/confirm', '/login', '/logout', '/forgot', '/reset', '/resend', '/account/password', '/account/delete'];

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_a_post_without_the_csrf_token_is_refused_on_every_member_form(): void
    {
        $this->flowSite();
        $this->makeMember();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        foreach (self::FORMS as $form) {
            foreach (['/members', '/de/members'] as $base) {
                $this->assertSame(403, $this->send('POST', $base . $form, ['email' => 'ada@example.com', 'current' => self::PASSWORD, 'confirm' => '1'])->getStatusCode(), $base . $form);
                $this->assertSame(403, $this->send('POST', $base . $form, ['email' => 'ada@example.com', '_csrf_token' => 'forged'])->getStatusCode(), $base . $form . ' forged');
            }
        }
        $this->assertNotNull($this->member('ada@example.com'), 'nothing was deleted');
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'still signed in');
    }

    #[RunInSeparateProcess]
    public function test_a_public_page_gets_no_session_and_no_cookie_while_a_member_page_does(): void
    {
        $this->flowSite(['path' => '/konto'], ['de', 'fr']);
        $m = $this->makeMember('ada@example.com', 'member', 'fr');
        $remember = $this->flow->container()->get(\Preflow\Auth\Remember\RememberMe::class)->issue('members', (string) $m->getId());

        foreach (['/', '/fr', '/kontofoo'] as $uri) {
            unset($_SESSION);
            $this->flow = $this->app();
            $response = $this->send('GET', $uri, cookies: ['site_member' => $remember]);
            $this->assertSame([], $response->getHeader('Set-Cookie'), $uri);
            $this->assertFalse($this->flow->container()->get(SessionInterface::class)->isStarted(), $uri . ': no session');
        }

        unset($_SESSION);
        $this->flow = $this->app();
        $member = $this->send('GET', '/fr/konto/account', cookies: ['site_member' => $remember]);
        $this->assertTrue($this->flow->container()->get(SessionInterface::class)->isStarted(), 'a member page has its session');
        $this->assertSame(200, $member->getStatusCode(), 'the remember cookie signs in there');
        $this->assertMatchesRegularExpression('~^site_member=[^;]+; Max-Age=~', (string) $this->cookie($member, 'site_member'), 'the rotated cookie');
    }

    #[RunInSeparateProcess]
    public function test_the_routes_answer_under_the_path_in_every_locale(): void
    {
        $this->flowSite(['path' => '/konto'], ['de', 'fr', 'en']);

        foreach (['', '/fr', '/en'] as $prefix) {
            foreach (['/register', '/login', '/forgot', '/confirm/abc', '/reset/abc'] as $page) {
                $this->assertSame(200, $this->send('GET', $prefix . '/konto' . $page)->getStatusCode(), $prefix . $page);
            }
            $this->assertSame(303, $this->send('GET', $prefix . '/konto/account')->getStatusCode());
            $this->assertSame($prefix . '/konto/login', $this->send('GET', $prefix . '/konto/resend')->getHeaderLine('Location'));
        }
        $this->assertSame(404, $this->send('GET', '/members/login')->getStatusCode(), 'nothing under the default path');
    }
}
