<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Csrf\CsrfSignInPaths;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * CSRF through the member pages: the login and the registration under the configured members path
 * are sign-in paths (matched without the locale prefix), so a login form left open past the
 * session's end still signs in, and a public page (no session) posts its stateless token there,
 * also for a visitor with a session. Other member forms of an expired session are sent back.
 */
final class CsrfSignInTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_expired_session_on_a_localized_sign_in_path_passes(): void
    {
        $this->flowSite(['path' => '/konto'], ['en', 'de']);
        $this->makeMember();
        $token = $this->formToken($this->html('/de/konto/login'));
        $this->assertMatchesRegularExpression('~^[0-9a-f]{64}$~', $token, 'a session token');

        // The session ran out while the form was open: same cookie, a new session without a token.
        $this->useSession([]);
        $response = $this->browserPost('/de/konto/login', ['_csrf_token' => $token, 'email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/de/konto', $response->getHeaderLine('Location'));
        $this->assertSame(200, $this->send('GET', '/de/konto/account')->getStatusCode(), 'signed in');
    }

    #[RunInSeparateProcess]
    public function test_expired_session_on_another_members_form_is_sent_back(): void
    {
        $this->flowSite(['path' => '/konto'], ['en', 'de']);
        $this->makeMember();
        $token = $this->formToken($this->html('/de/konto/forgot'));

        $this->useSession([]);
        $response = $this->browserPost('/de/konto/forgot', ['_csrf_token' => $token, 'email' => 'ada@example.com'], referer: 'https://site.test/de/konto/forgot');

        $this->assertSame(303, $response->getStatusCode(), 'not a sign-in path: not applied');
        $this->assertSame('/de/konto/forgot', $response->getHeaderLine('Location'));
        $this->assertSame([], $this->mails());

        // The form again, with the warning and the address kept.
        $html = html_entity_decode($this->html('/de/konto/forgot'), ENT_QUOTES);
        $this->assertStringContainsString('Deine Sitzung war abgelaufen. Bitte sende das Formular noch einmal.', $html);
        $this->assertStringContainsString('value="ada@example.com"', $html);
    }

    #[RunInSeparateProcess]
    public function test_login_and_register_are_sign_in_paths(): void
    {
        $this->flowSite(['path' => '/konto/'], ['en']);
        $paths = $this->flow->container()->get(CsrfSignInPaths::class);

        $this->assertTrue($paths->matches('/konto/login'));
        $this->assertTrue($paths->matches('/konto/register'));
        $this->assertFalse($paths->matches('/konto/forgot'));
    }

    #[RunInSeparateProcess]
    public function test_public_form_posting_into_members_area_with_stateless_token_passes(): void
    {
        $this->flowSite(files: ['app/pages/join.twig' => '<form method="post" action="/members/register">{{ csrf_field() }}</form>']);
        // The visitor has a live session from the members area.
        $this->html('/members/forgot');
        $this->assertNotEmpty($_SESSION['_csrf_token'] ?? null);

        $token = $this->publicFormToken('/join');
        $this->assertStringStartsWith('v1.', $token, 'the public page has no session: a stateless token');
        $response = $this->browserPost('/members/register', ['_csrf_token' => $token, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Check your inbox', html_entity_decode((string) $response->getBody(), ENT_QUOTES));
        $this->assertCount(1, $this->mails());
    }

    #[RunInSeparateProcess]
    public function test_a_live_session_refuses_a_stateless_token_outside_the_sign_in_paths(): void
    {
        $this->flowSite(files: ['app/pages/ask.twig' => '<form method="post" action="/members/forgot">{{ csrf_field() }}</form>']);
        $this->makeMember();
        $this->html('/members/forgot');

        $token = $this->publicFormToken('/ask');
        $this->assertStringStartsWith('v1.', $token);
        $response = $this->browserPost('/members/forgot', ['_csrf_token' => $token, 'email' => 'ada@example.com']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->mails());
    }

    /** As an older browser posts a form of the site: no Sec-Fetch-Site, the site's Origin, the session cookie. @param array<string, string> $body */
    private function browserPost(string $uri, array $body, string $referer = ''): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', $uri, ['REMOTE_ADDR' => $this->remoteAddr])
            ->withHeader('Origin', 'https://site.test')
            ->withCookieParams([session_name() => session_id()])
            ->withParsedBody($body);
        if ($referer !== '') {
            $request = $request->withHeader('Referer', $referer);
        }

        return $this->flow->handle($request);
    }

    /** The form token of a page outside the session paths, as a browser gets it: that request starts no session. */
    private function publicFormToken(string $uri): string
    {
        $session = $this->useSession([]);
        $token = $this->formToken($this->html($uri));
        $this->useSession($session);

        return $token;
    }

    private function formToken(string $html): string
    {
        preg_match('~name="_csrf_token" value="([^"]+)"~', $html, $m);

        return $m[1] ?? $this->fail('No CSRF field on the page');
    }
}
