<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/RedirectingExpiredSessionHandler.php';
require_once __DIR__ . '/Fixtures/ExpiredSessionHandlerProvider.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\Csrf\StatelessCsrfToken;
use Preflow\Core\Http\OldInput;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Tests\Fixtures\ExpiredSessionHandlerProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The Application's CsrfMiddleware asks a bound ExpiredSessionHandler (bound by a service provider).
 * A middleware added after boot stands in for the route: it runs only when the check let the request through.
 */
final class CsrfExpiredSessionBootTest extends TestCase
{
    private bool $routeRan = false;

    #[RunInSeparateProcess]
    public function test_a_bound_handler_answers_a_post_whose_session_expired(): void
    {
        $app = $this->booted(withProvider: true);

        $response = $app->handle($this->post(['_csrf_token' => 'token-of-the-old-session', 'title' => 'typed']));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/login?next=/objects/1/edit', $response->getHeaderLine('Location'));
        $this->assertSame('yes', $response->getHeaderLine('X-Has-Token'));
        $this->assertSame('typed', $app->container()->get(SessionInterface::class)->get('pending_title'));
        $this->assertFalse($this->routeRan, 'never applied');
    }

    #[RunInSeparateProcess]
    public function test_a_bound_handler_is_not_asked_for_a_forged_token_on_a_live_session(): void
    {
        $app = $this->booted(withProvider: true);
        $app->container()->get(SessionInterface::class)->set('_csrf_token', 'the-live-token');

        $response = $app->handle($this->post(['_csrf_token' => 'forged', 'title' => 'typed']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($app->container()->get(SessionInterface::class)->get('pending_title'));
        $this->assertFalse($this->routeRan);
    }

    #[RunInSeparateProcess]
    public function test_without_a_handler_an_expired_session_sends_the_form_back(): void
    {
        $app = $this->booted(withProvider: false);

        $response = $app->handle($this->post(['_csrf_token' => 'token-of-the-old-session', 'title' => 'typed']));

        // The framework's default answer: not applied, sent back (no Referer: home) with the fields kept.
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
        $session = $app->container()->get(SessionInterface::class);
        $this->assertSame('Your session had expired. Please send the form again.', $session->getFlash('warning'));
        $this->assertSame(['title' => 'typed'], $app->container()->get(OldInput::class)->all());
        $this->assertFalse($this->routeRan, 'never applied');
    }

    #[RunInSeparateProcess]
    public function test_a_session_token_posted_without_the_session_cookie_but_with_remember_me_goes_to_the_handler(): void
    {
        $response = $this->booted(withProvider: true)->handle($this->postWithoutSessionCookie());

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/login?next=/objects/1/edit', $response->getHeaderLine('Location'));
        $this->assertFalse($this->routeRan, 'never applied');
    }

    #[RunInSeparateProcess]
    public function test_a_session_token_posted_without_the_session_cookie_but_with_remember_me_is_sent_back(): void
    {
        $app = $this->booted(withProvider: false);
        $response = $app->handle($this->postWithoutSessionCookie());

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame(['title' => 'typed'], $app->container()->get(OldInput::class)->all());
        $this->assertFalse($this->routeRan, 'never applied');
    }

    #[RunInSeparateProcess]
    public function test_a_public_token_with_remember_me_and_no_origin_signal_is_refused(): void
    {
        $app = $this->booted(withProvider: true);
        $publicToken = $app->container()->get(StatelessCsrfToken::class)->issue();

        $response = $app->handle((new Psr17Factory())->createServerRequest('POST', '/objects/1/edit')
            ->withCookieParams(['site_remember' => 'member-1|secret'])
            ->withParsedBody(['_csrf_token' => $publicToken, 'title' => 'forged']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->routeRan, 'never applied');
    }

    /** The session cookie ran out in the browser (its lifetime); the remember-me cookie did not. */
    private function postWithoutSessionCookie(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', '/objects/1/edit')
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withCookieParams(['site_remember' => 'member-1|secret'])
            ->withParsedBody(['_csrf_token' => str_repeat('ab', 32), 'title' => 'typed']);
    }

    private function booted(bool $withProvider): Application
    {
        $app = Application::create($this->app($withProvider));
        $app->boot();
        $app->addMiddleware(new class($this->routeRan) implements MiddlewareInterface {
            public function __construct(private bool &$ran) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->ran = true;
                return new Response(200);
            }
        });

        return $app;
    }

    /**
     * As a browser sends it: from a page of the site, with the (old) session cookie.
     *
     * @param array<string, string> $body
     */
    private function post(array $body): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', '/objects/1/edit')
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withCookieParams(['csrf_expired_test' => 'id-of-the-old-session'])
            ->withParsedBody($body);
    }

    private function app(bool $withProvider): string
    {
        $dir = sys_get_temp_dir() . '/csrfexpired_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($dir . '/config/auth.php', "<?php\nreturn ["
            . "'default_guard' => 'session',"
            . "'guards' => ['session' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'config']],"
            . "'providers' => ['config' => ['class' => \\Preflow\\Auth\\ConfigUserProvider::class, 'users' => []]],"
            . "'password_hasher' => \\Preflow\\Auth\\NativePasswordHasher::class,"
            . "'session' => ['cookie' => 'csrf_expired_test'],"
            . "];\n");
        if ($withProvider) {
            file_put_contents($dir . '/config/providers.php', "<?php\nreturn [\\" . ExpiredSessionHandlerProvider::class . "::class];\n");
        }

        return $dir;
    }
}
