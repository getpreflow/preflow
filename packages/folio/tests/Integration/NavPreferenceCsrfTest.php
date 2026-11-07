<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Psr\Http\Message\ResponseInterface;

/** The sidebar save endpoint runs behind the CSRF middleware of the full app. */
final class NavPreferenceCsrfTest extends TestCase
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
    public function test_with_the_token_it_saves(): void
    {
        $this->makeStaff();
        $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD]);
        preg_match('~name="_csrf_token" value="([^"]+)"~', $this->html('/folio/_account'), $m);

        $response = $this->browserPost('/folio/_prefs/nav', ['_csrf_token' => $m[1] ?? '', 'section' => 'settings', 'open' => '0']);

        $this->assertSame(204, $response->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_without_csrf_token_it_is_refused(): void
    {
        $this->makeStaff();
        $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD]);

        $response = $this->browserPost('/folio/_prefs/nav', ['section' => 'settings', 'open' => '0']);

        $this->assertSame(403, $response->getStatusCode());
    }

    /** @param array<string, string> $body */
    private function browserPost(string $uri, array $body): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', $uri, ['REMOTE_ADDR' => $this->remoteAddr])
            ->withHeader('Origin', 'https://site.test')
            ->withCookieParams([session_name() => session_id()])
            ->withParsedBody($body);

        return $this->flow->handle($request);
    }
}
