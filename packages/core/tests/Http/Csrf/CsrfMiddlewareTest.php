<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Csrf;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Core\Error\DevErrorRenderer;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Http\Csrf\CsrfConfig;
use Preflow\Core\Http\Csrf\CsrfMiddleware;
use Preflow\Core\Http\Csrf\CsrfSessionExpiredException;
use Preflow\Core\Http\Csrf\CsrfSignInPaths;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Http\Csrf\ExpiredSessionHandler;
use Preflow\Core\Http\Csrf\OriginCheck;
use Preflow\Core\Http\Csrf\StatelessCsrfToken;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Kernel;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Testing\ArraySession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfMiddlewareTest extends TestCase
{
    private const KEY = 'a-real-application-key-of-32-bytes';
    private const COOKIE = 'site_session';
    private const OLD_SESSION_TOKEN = 'a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1';

    /** @var list<string> */
    private array $warnings = [];

    // --- 1. exempt path prefixes ---

    public function test_exempt_prefix_passes(): void
    {
        $request = $this->post('/--component/render', ['Sec-Fetch-Site' => 'cross-site']);

        $this->assertSame(200, $this->middleware()->process($request, $this->app())->getStatusCode());
    }

    // --- 2. API clients ---

    public function test_authorization_without_cookie_passes(): void
    {
        $request = $this->post('/api/items', ['Authorization' => 'Bearer abc']);

        $this->assertSame(200, $this->middleware()->process($request, $this->app())->getStatusCode());
    }

    public function test_authorization_with_cookie_is_checked(): void
    {
        $request = $this->post('/api/items', ['Authorization' => 'Bearer abc'])->withCookieParams(['other' => '1']);

        $this->assertRefused('no-signal', fn () => $this->middleware()->process($request, $this->app()));
    }

    // --- 3. routes marked #[CsrfExempt] ---

    public function test_route_marked_exempt_passes_and_route_is_kept_on_request(): void
    {
        $route = new Route(RouteMode::Action, 'Webhook@receive', csrfExempt: true);
        $router = $this->router($route);
        $request = $this->post('/webhook', ['Sec-Fetch-Site' => 'cross-site']);

        $captured = null;
        $response = $this->middleware(router: fn () => $router)->process($request, $this->app($captured));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($route, $captured?->getAttribute(Route::class));
        $this->assertSame(1, $router->calls);

        // A route that isn't exempt is checked, and still kept on the request.
        $plain = new Route(RouteMode::Action, 'Contact@send');
        $request = $this->post('/contact', ['Sec-Fetch-Site' => 'same-origin']);
        $this->middleware(router: fn () => $this->router($plain))->process($request, $this->app($captured));
        $this->assertSame($plain, $captured?->getAttribute(Route::class));
        $this->assertRefused('origin', fn () => $this->middleware(router: fn () => $this->router($plain))
            ->process($this->post('/contact', ['Sec-Fetch-Site' => 'cross-site']), $this->app()));
    }

    public function test_unmatched_route_falls_through_to_404_when_origin_passes(): void
    {
        $router = new \Preflow\Routing\Router();
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($this->middleware(router: fn () => $router));
        $kernel = new Kernel(
            container: new Container(),
            router: $router,
            pipeline: $pipeline,
            errorHandler: new ErrorHandler(new DevErrorRenderer()),
            actionDispatcher: fn () => new Response(200),
            componentRenderer: fn () => new Response(200),
        );

        $this->assertSame(404, $kernel->handle($this->post('/nowhere', ['Sec-Fetch-Site' => 'same-origin']))->getStatusCode());
        $this->assertSame(403, $kernel->handle($this->post('/nowhere', ['Sec-Fetch-Site' => 'cross-site']))->getStatusCode());
    }

    // --- 4. a refused origin wins over any token ---

    public function test_cross_site_refused_even_with_valid_session_token(): void
    {
        $session = $this->liveSession();
        $request = $this->withSession($this->post('/account', ['Sec-Fetch-Site' => 'cross-site'], [
            '_csrf_token' => CsrfToken::fromSession($session)?->getValue(),
        ]), $session);

        $this->assertRefused('origin', fn () => $this->middleware()->process($request, $this->app()));
    }

    // --- 5. without a session (or without its cookie) ---

    public function test_public_post_same_origin_needs_no_token(): void
    {
        $request = $this->post('/contact', ['Origin' => 'https://example.com']);

        $this->assertSame(200, $this->middleware()->process($request, $this->app())->getStatusCode());
    }

    public function test_public_post_without_signals_needs_stateless_token(): void
    {
        $middleware = $this->middleware();
        $valid = $this->stateless()->issue();

        $this->assertSame(200, $middleware->process($this->post('/contact', [], ['_csrf_token' => $valid]), $this->app())->getStatusCode());
        $this->assertSame(200, $middleware->process($this->post('/contact', ['X-CSRF-Token' => $valid]), $this->app())->getStatusCode());
        $this->assertRefused('no-signal', fn () => $middleware->process($this->post('/contact'), $this->app()));
        $this->assertRefused('no-signal', fn () => $middleware->process($this->post('/contact', [], ['_csrf_token' => 'v1.1.forged']), $this->app()));
        $this->assertRefused('no-signal', fn () => $middleware->process(
            $this->post('/contact', [], ['_csrf_token' => (new StatelessCsrfToken('another-key-of-the-right-length!'))->issue()]),
            $this->app(),
        ));
        $this->assertSame([], $this->warnings);
    }

    public function test_public_post_without_signals_refused_when_key_is_default(): void
    {
        $middleware = $this->middleware(key: StatelessCsrfToken::DEFAULT_KEY);
        $signedWithDefault = $this->forge(StatelessCsrfToken::DEFAULT_KEY);

        $this->assertRefused('no-signal', fn () => $middleware->process($this->post('/contact', [], ['_csrf_token' => $signedWithDefault]), $this->app()));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('app.key', $this->warnings[0]);

        // Origin signals still work without the key, and need no warning.
        $this->assertSame(200, $middleware->process($this->post('/contact', ['Sec-Fetch-Site' => 'same-origin']), $this->app())->getStatusCode());
        $this->assertCount(1, $this->warnings);
    }

    public function test_cookieless_visitor_on_session_path_is_treated_as_public(): void
    {
        $session = new ArraySession();
        $session->start();
        $middleware = $this->middleware();

        // The session middleware started a session, but the browser sent no session cookie.
        $sameOrigin = $this->withSession($this->post('/members/profile', ['Sec-Fetch-Site' => 'same-origin']), $session, cookie: false);
        $this->assertSame(200, $middleware->process($sameOrigin, $this->app())->getStatusCode());

        $noSignal = $this->withSession($this->post('/members/profile', [], ['_csrf_token' => $this->stateless()->issue()]), $session, cookie: false);
        $this->assertSame(200, $middleware->process($noSignal, $this->app())->getStatusCode());

        $nothing = $this->withSession($this->post('/members/profile'), $session, cookie: false);
        $this->assertRefused('no-signal', fn () => $middleware->process($nothing, $this->app()));
    }

    // --- 6. a live session ---

    public function test_live_session_needs_session_token_and_origin(): void
    {
        $session = $this->liveSession();
        $token = CsrfToken::fromSession($session)?->getValue();
        $middleware = $this->middleware();
        $post = fn (array $headers, array $body = []) => $this->withSession($this->post('/account', $headers, $body), $session);

        $this->assertSame(200, $middleware->process($post(['Sec-Fetch-Site' => 'same-origin'], ['_csrf_token' => $token]), $this->app())->getStatusCode());
        $this->assertSame(200, $middleware->process($post(['X-CSRF-Token' => $token]), $this->app())->getStatusCode());
        $this->assertRefused('token', fn () => $middleware->process($post(['Sec-Fetch-Site' => 'same-origin']), $this->app()));
        $this->assertRefused('token', fn () => $middleware->process($post(['Sec-Fetch-Site' => 'same-origin'], ['_csrf_token' => 'forged']), $this->app()));
        $this->assertRefused('token', fn () => $middleware->process(
            $post(['Sec-Fetch-Site' => 'same-origin'], ['_csrf_token' => $this->stateless()->issue()]),
            $this->app(),
        ));
        $this->assertRefused('origin', fn () => $middleware->process($post(['Origin' => 'https://evil.test'], ['_csrf_token' => $token]), $this->app()));
    }

    // --- 7. a new session (the one the form came from is gone, or the page was public) ---

    public function test_new_session_accepts_valid_stateless_token_with_passing_origin(): void
    {
        $session = $this->newSession();
        $request = $this->withSession($this->post('/members/like', ['Sec-Fetch-Site' => 'same-origin'], [
            '_csrf_token' => $this->stateless()->issue(),
        ]), $session);

        $captured = null;
        $response = $this->middleware()->process($request, $this->app($captured));

        $this->assertSame(200, $response->getStatusCode());
        $token = $captured?->getAttribute(CsrfToken::class);
        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertFalse($token->isStateless(), 'the page rendered next uses the new session token');
        $this->assertSame(CsrfToken::fromSession($session)?->getValue(), $token->getValue());
    }

    public function test_new_session_refuses_stateless_token_without_signal(): void
    {
        $request = $this->withSession($this->post('/members/like', [], ['_csrf_token' => $this->stateless()->issue()]), $this->newSession());

        $this->assertRefused('no-signal', fn () => $this->middleware()->process($request, $this->app()));
    }

    public function test_new_session_without_token_is_refused(): void
    {
        $handler = $this->expiredHandler(new Response(303));
        $request = $this->withSession($this->post('/account', ['Sec-Fetch-Site' => 'same-origin'], ['_csrf_token' => '']), $this->newSession());

        $this->assertRefused('token', fn () => $this->middleware(handler: $handler)->process($request, $this->app()));
        $this->assertSame([], $handler->calls);
    }

    public function test_expired_session_on_sign_in_path_passes(): void
    {
        $handler = $this->expiredHandler(new Response(303));
        $middleware = $this->middleware(signIn: ['/members/login/'], handler: $handler);

        foreach (['/members/login', '/members/login/'] as $path) {
            $request = $this->withSession($this->post($path, ['Sec-Fetch-Site' => 'same-origin'], [
                '_csrf_token' => self::OLD_SESSION_TOKEN,
                'password' => 'secret',
            ]), $this->newSession());

            $captured = null;
            $this->assertSame(200, $middleware->process($request, $this->app($captured))->getStatusCode());
            $this->assertNotNull($captured);
        }

        $this->assertSame([], $handler->calls);

        // Only the exact path: a sub path is another form.
        $request = $this->withSession($this->post('/members/login/extra', ['Sec-Fetch-Site' => 'same-origin'], [
            '_csrf_token' => self::OLD_SESSION_TOKEN,
        ]), $this->newSession());
        $this->assertSame(303, $middleware->process($request, $this->app())->getStatusCode());
    }

    public function test_expired_session_elsewhere_uses_site_handler_first(): void
    {
        $responded = [];
        $responder = function (ServerRequestInterface $request) use (&$responded): ResponseInterface {
            $responded[] = $request;
            return new Response(409);
        };
        $request = fn () => $this->withSession($this->post('/objects/1/edit', ['Origin' => 'https://example.com'], [
            '_csrf_token' => self::OLD_SESSION_TOKEN,
            'title' => 'typed',
        ]), $this->newSession());

        // The site's handler answers first; the app doesn't run.
        $handler = $this->expiredHandler(new Response(303, ['Location' => '/login']));
        $captured = null;
        $response = $this->middleware(handler: $handler, responder: $responder)->process($request(), $this->app($captured));
        $this->assertSame(303, $response->getStatusCode());
        $this->assertNull($captured);
        $this->assertCount(1, $handler->calls);
        $this->assertInstanceOf(CsrfToken::class, $handler->calls[0]->getAttribute(CsrfToken::class));
        $this->assertSame([], $responded);

        // A handler that declines leaves it to the framework's responder.
        $declining = $this->expiredHandler(null);
        $response = $this->middleware(handler: $declining, responder: $responder)->process($request(), $this->app($captured));
        $this->assertSame(409, $response->getStatusCode());
        $this->assertCount(1, $declining->calls);
        $this->assertCount(1, $responded);
        $this->assertNull($captured);

        // Neither: the exception, as before.
        $e = $this->catch(fn () => $this->middleware()->process($request(), $this->app()));
        $this->assertInstanceOf(CsrfSessionExpiredException::class, $e);
    }

    public function test_expired_session_without_signal_throws_expired_exception(): void
    {
        $handler = $this->expiredHandler(new Response(303));
        $middleware = $this->middleware(signIn: ['/members/login'], handler: $handler);

        foreach (['/members/login', '/objects/1/edit'] as $path) {
            $request = $this->withSession($this->post($path, [], ['_csrf_token' => self::OLD_SESSION_TOKEN]), $this->newSession());

            $e = $this->catch(fn () => $middleware->process($request, $this->app()));
            $this->assertInstanceOf(CsrfSessionExpiredException::class, $e);
        }

        $this->assertSame([], $handler->calls);
    }

    // --- the CsrfToken request attribute ---

    public function test_token_attribute_is_stateless_without_session(): void
    {
        $captured = null;
        $this->middleware()->process($this->get('/'), $this->app($captured));
        $token = $captured?->getAttribute(CsrfToken::class);
        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertTrue($token->isStateless());
        $this->assertTrue($this->stateless()->verify($token->getValue()));

        // With a session: the session token.
        $session = $this->newSession();
        $this->middleware()->process($this->withSession($this->get('/'), $session), $this->app($captured));
        $token = $captured?->getAttribute(CsrfToken::class);
        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertFalse($token->isStateless());
        $this->assertSame(CsrfToken::fromSession($session)?->getValue(), $token->getValue());

        // Without a session and without a usable key: none.
        $this->middleware(key: StatelessCsrfToken::DEFAULT_KEY)->process($this->get('/'), $this->app($captured));
        $this->assertNotNull($captured);
        $this->assertNull($captured->getAttribute(CsrfToken::class));

        // Unsafe requests carry it too.
        $this->middleware()->process($this->post('/contact', ['Sec-Fetch-Site' => 'same-origin']), $this->app($captured));
        $this->assertTrue($captured?->getAttribute(CsrfToken::class)?->isStateless());
    }

    public function test_safe_methods_pass_without_checks(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $request = (new Psr17Factory())->createServerRequest($method, 'https://example.com/x')
                ->withHeader('Sec-Fetch-Site', 'cross-site');
            $this->assertSame(200, $this->middleware()->process($request, $this->app())->getStatusCode());
        }
    }

    public function test_session_cookie_name_defaults_to_session_name(): void
    {
        $middleware = new CsrfMiddleware(
            new CsrfConfig(),
            new OriginCheck('https://example.com'),
            $this->stateless(),
            new CsrfSignInPaths(),
            null,
            null,
            null,
            null,
        );
        $request = $this->post('/account', ['Sec-Fetch-Site' => 'same-origin'])
            ->withAttribute(SessionInterface::class, $this->liveSession())
            ->withCookieParams([session_name() => 'abc']);

        $this->assertRefused('token', fn () => $middleware->process($request, $this->app()));
    }

    // --- helpers ---

    /** @param list<string> $signIn */
    private function middleware(
        array $signIn = [],
        ?\Closure $router = null,
        ?ExpiredSessionHandler $handler = null,
        ?\Closure $responder = null,
        string $key = self::KEY,
    ): CsrfMiddleware {
        $paths = new CsrfSignInPaths();
        foreach ($signIn as $path) {
            $paths->add($path);
        }

        return new CsrfMiddleware(
            new CsrfConfig(),
            new OriginCheck('https://example.com'),
            new StatelessCsrfToken($key),
            $paths,
            self::COOKIE,
            $router,
            $handler,
            $responder,
            function (string $message): void {
                $this->warnings[] = $message;
            },
        );
    }

    private function stateless(): StatelessCsrfToken
    {
        return new StatelessCsrfToken(self::KEY);
    }

    /** A token correctly signed with $key for the current slot. */
    private function forge(string $key): string
    {
        $slot = intdiv(time(), 86400);
        $mac = hash_hmac('sha256', "v1|$slot|preflow.csrf", $key, true);

        return 'v1.' . $slot . '.' . rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private function post(string $path, array $headers = [], array $body = []): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://example.com' . $path)->withParsedBody($body);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function get(string $path): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'https://example.com' . $path);
    }

    private function withSession(ServerRequestInterface $request, SessionInterface $session, bool $cookie = true): ServerRequestInterface
    {
        $request = $request->withAttribute(SessionInterface::class, $session);

        return $cookie ? $request->withCookieParams([self::COOKIE => $session->getId()]) : $request;
    }

    /** A session that held a token before this request. */
    private function liveSession(): ArraySession
    {
        $session = new ArraySession();
        $session->start();
        CsrfToken::generate($session);

        return $session;
    }

    /** A session started for this request, without a token yet. */
    private function newSession(): ArraySession
    {
        $session = new ArraySession();
        $session->start();

        return $session;
    }

    private function app(?ServerRequestInterface &$captured = null): RequestHandlerInterface
    {
        $captured = null;

        return new class($captured) implements RequestHandlerInterface {
            public function __construct(private ?ServerRequestInterface &$captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response(200);
            }
        };
    }

    private function router(Route $route): RouterInterface
    {
        return new class($route) implements RouterInterface {
            public int $calls = 0;

            public function __construct(private readonly Route $route) {}

            public function match(ServerRequestInterface $request): Route
            {
                $this->calls++;
                return $this->route;
            }
        };
    }

    private function assertRefused(string $reason, \Closure $run): void
    {
        $e = $this->catch($run);
        $this->assertInstanceOf(ForbiddenHttpException::class, $e);
        $this->assertNotInstanceOf(CsrfSessionExpiredException::class, $e);
        $this->assertSame('CSRF check failed: ' . $reason . '.', $e->getMessage());
    }

    private function catch(\Closure $run): \Throwable
    {
        try {
            $run();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('expected an exception');
    }

    private function expiredHandler(?ResponseInterface $answer): object
    {
        return new class($answer) implements ExpiredSessionHandler {
            /** @var list<ServerRequestInterface> */
            public array $calls = [];

            public function __construct(private readonly ?ResponseInterface $answer) {}

            public function handle(ServerRequestInterface $request): ?ResponseInterface
            {
                $this->calls[] = $request;
                return $this->answer;
            }
        };
    }
}
