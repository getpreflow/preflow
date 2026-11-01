<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * CSRF through Folio's real flows: the staff login is a sign-in path, so a login form left open
 * past the session's end still signs in (origin passes, the password is the proof); admin forms
 * with a live session post the token Folio prints from the request attribute.
 */
final class CsrfSignInTest extends TestCase
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
    public function test_staff_login_after_expired_session_signs_in(): void
    {
        $this->makeStaff();
        $page = $this->html('/folio/login');
        $token = $this->formToken($page);
        $this->assertMatchesRegularExpression('~^[0-9a-f]{64}$~', $token, 'a session token');

        // The session ran out while the form was open: same cookie, a new session without a token.
        $this->useSession([]);
        $response = $this->browserPost('/folio/login', ['_csrf_token' => $token, 'email' => 'ada@site.test', 'password' => self::PASSWORD]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/folio', $response->getHeaderLine('Location'));
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'signed in');
    }

    #[RunInSeparateProcess]
    public function test_admin_form_with_live_session_still_posts(): void
    {
        $this->makeStaff();
        $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD]);

        $page = $this->html('/folio/_account');
        $this->assertMatchesRegularExpression('~<form[^>]+action="/folio/_account/password"~', $page);
        $token = $this->formToken($page);

        $response = $this->browserPost('/folio/_account/password', [
            '_csrf_token' => $token, 'current' => self::PASSWORD, 'password' => 'a brand new password', 'repeat' => 'a brand new password',
        ]);

        $this->assertSame('/folio/_account', $response->getHeaderLine('Location'));
        $this->assertTrue(password_verify('a brand new password', (string) $this->staff('ada@site.test')->get('password_hash')));
    }

    #[RunInSeparateProcess]
    public function test_admin_form_with_a_wrong_token_is_refused(): void
    {
        $this->makeStaff();
        $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD]);

        $response = $this->browserPost('/folio/_account/password', [
            '_csrf_token' => str_repeat('a', 64), 'current' => self::PASSWORD, 'password' => 'a brand new password', 'repeat' => 'a brand new password',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue(password_verify(self::PASSWORD, (string) $this->staff('ada@site.test')->get('password_hash')), 'unchanged');
    }

    /** As an older browser posts a form of the site: no Sec-Fetch-Site, the site's Origin, the session cookie. @param array<string, string> $body */
    private function browserPost(string $uri, array $body): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', $uri, ['REMOTE_ADDR' => $this->remoteAddr])
            ->withHeader('Origin', 'https://site.test')
            ->withCookieParams([session_name() => session_id()])
            ->withParsedBody($body);

        return $this->flow->handle($request);
    }

    private function formToken(string $html): string
    {
        preg_match('~name="_csrf_token" value="([^"]+)"~', $html, $m);

        return $m[1] ?? $this->fail('No CSRF field on the page');
    }
}
