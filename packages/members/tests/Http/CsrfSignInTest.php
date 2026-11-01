<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * CSRF through the member pages: the login under the configured members path is a sign-in path
 * (matched without the locale prefix), so a login form left open past the session's end still
 * signs in; a public page (no session) posts into the members area with the stateless token.
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
        $token = $this->formToken($this->html('/de/konto/register'));

        $this->useSession([]);
        $response = $this->browserPost('/de/konto/register', ['_csrf_token' => $token, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);

        $this->assertSame(303, $response->getStatusCode(), 'not a sign-in path: not applied');
        $this->assertSame([], $this->mails());
    }

    #[RunInSeparateProcess]
    public function test_public_form_posting_into_members_area_with_stateless_token_passes(): void
    {
        $this->flowSite(files: ['app/pages/join.twig' => '<form method="post" action="/members/register">{{ csrf_field() }}</form>']);
        // An old visit left a session cookie behind.
        $this->html('/members/forgot');
        $this->useSession([]);

        $token = $this->formToken($this->html('/join'));
        $this->assertStringStartsWith('v1.', $token, 'the public page has no session: a stateless token');
        $response = $this->browserPost('/members/register', ['_csrf_token' => $token, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Check your inbox', html_entity_decode((string) $response->getBody(), ENT_QUOTES));
        $this->assertCount(1, $this->mails());
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
