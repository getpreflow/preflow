<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Csrf;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Http\Csrf\CsrfMiddleware;
use Preflow\Core\Http\Csrf\CsrfSessionExpiredException;
use Preflow\Core\Http\Csrf\ExpiredSessionHandler;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Testing\ArraySession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfMiddlewareTest extends TestCase
{
    private function createHandler(?ServerRequestInterface &$captured = null): RequestHandlerInterface
    {
        return new class($captured) implements RequestHandlerInterface {
            public function __construct(private ?ServerRequestInterface &$captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response(200);
            }
        };
    }

    private function createRequestWithSession(string $method, string $path = '/', ?ArraySession $session = null): array
    {
        $session ??= new ArraySession();
        $session->start();

        $request = (new Psr17Factory())->createServerRequest($method, $path);
        $request = $request->withAttribute(SessionInterface::class, $session);

        return [$request, $session];
    }

    public function test_get_request_generates_token_and_attaches_as_attribute(): void
    {
        [$request] = $this->createRequestWithSession('GET');
        $middleware = new CsrfMiddleware();

        $captured = null;
        $middleware->process($request, $this->createHandler($captured));

        $this->assertNotNull($captured);
        $token = $captured->getAttribute(CsrfToken::class);
        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertNotEmpty($token->getValue());
    }

    public function test_post_with_valid_token_in_form_body_passes(): void
    {
        [$request, $session] = $this->createRequestWithSession('POST');
        $middleware = new CsrfMiddleware();

        // Generate a token in the session first
        $token = CsrfToken::generate($session);

        $request = $request->withParsedBody(['_csrf_token' => $token->getValue()]);

        $response = $middleware->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_with_missing_token_throws_forbidden(): void
    {
        [$request] = $this->createRequestWithSession('POST');
        $middleware = new CsrfMiddleware();

        $this->expectException(ForbiddenHttpException::class);
        $this->expectExceptionMessage('CSRF token mismatch.');

        $middleware->process($request, $this->createHandler());
    }

    public function test_post_with_invalid_token_throws_forbidden(): void
    {
        [$request, $session] = $this->createRequestWithSession('POST');
        $middleware = new CsrfMiddleware();

        // Generate a valid token in session, then submit a wrong one
        CsrfToken::generate($session);

        $request = $request->withParsedBody(['_csrf_token' => 'invalid-token-value']);

        $this->expectException(ForbiddenHttpException::class);
        $this->expectExceptionMessage('CSRF token mismatch.');

        $middleware->process($request, $this->createHandler());
    }

    public function test_post_with_valid_token_in_header_passes(): void
    {
        [$request, $session] = $this->createRequestWithSession('POST');
        $middleware = new CsrfMiddleware();

        $token = CsrfToken::generate($session);

        $request = $request->withHeader('X-CSRF-Token', $token->getValue());

        $response = $middleware->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_to_exempt_path_passes_without_token(): void
    {
        [$request] = $this->createRequestWithSession('POST', '/--component/render');
        $middleware = new CsrfMiddleware(['/--component/']);

        $response = $middleware->process($request, $this->createHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    // --- expired session: the session holds no token yet, the form carries one ---

    public function test_post_with_a_token_into_a_session_without_one_throws_session_expired(): void
    {
        [$request] = $this->createRequestWithSession('POST');
        $request = $request->withParsedBody(['_csrf_token' => 'token-of-the-old-session']);

        try {
            (new CsrfMiddleware())->process($request, $this->createHandler());
            $this->fail('expected an exception');
        } catch (CsrfSessionExpiredException $e) {
            $this->assertInstanceOf(ForbiddenHttpException::class, $e);
            $this->assertSame(403, $e->statusCode);
            $this->assertSame('CSRF token mismatch: session expired.', $e->getMessage());
        }
    }

    public function test_session_expired_is_also_detected_from_the_header(): void
    {
        [$request] = $this->createRequestWithSession('POST');
        $request = $request->withHeader('X-CSRF-Token', 'token-of-the-old-session');

        $this->expectException(CsrfSessionExpiredException::class);

        (new CsrfMiddleware())->process($request, $this->createHandler());
    }

    public function test_wrong_token_on_a_live_session_is_a_plain_mismatch(): void
    {
        [$request, $session] = $this->createRequestWithSession('POST');
        CsrfToken::generate($session);
        $request = $request->withParsedBody(['_csrf_token' => 'forged']);

        $e = $this->catch(fn () => (new CsrfMiddleware())->process($request, $this->createHandler()));

        $this->assertInstanceOf(ForbiddenHttpException::class, $e);
        $this->assertNotInstanceOf(CsrfSessionExpiredException::class, $e);
        $this->assertSame('CSRF token mismatch.', $e->getMessage());
    }

    public function test_missing_token_on_a_new_session_is_a_plain_mismatch(): void
    {
        foreach ([[], ['_csrf_token' => '']] as $body) {
            [$request] = $this->createRequestWithSession('POST');
            $request = $request->withParsedBody($body);

            $e = $this->catch(fn () => (new CsrfMiddleware())->process($request, $this->createHandler()));

            $this->assertInstanceOf(ForbiddenHttpException::class, $e);
            $this->assertNotInstanceOf(CsrfSessionExpiredException::class, $e);
        }
    }

    public function test_handler_answers_the_expired_case_and_the_app_is_not_called(): void
    {
        [$request, $session] = $this->createRequestWithSession('POST');
        $request = $request->withParsedBody(['_csrf_token' => 'old', 'title' => 'typed']);
        $handler = $this->expiredHandler(new Response(303, ['Location' => '/login']));

        $captured = null;
        $response = (new CsrfMiddleware(expiredSessionHandler: $handler))->process($request, $this->createHandler($captured));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertNull($captured, 'the app must not run');
        $this->assertCount(1, $handler->calls);
        $seen = $handler->calls[0];
        $this->assertSame($session, $seen->getAttribute(SessionInterface::class));
        $token = $seen->getAttribute(CsrfToken::class);
        $this->assertInstanceOf(CsrfToken::class, $token);
        $this->assertSame(CsrfToken::fromSession($session)?->getValue(), $token->getValue());
        $this->assertSame(['_csrf_token' => 'old', 'title' => 'typed'], $seen->getParsedBody());
    }

    public function test_handler_returning_null_falls_through_to_the_exception(): void
    {
        [$request] = $this->createRequestWithSession('POST');
        $request = $request->withParsedBody(['_csrf_token' => 'old']);
        $handler = $this->expiredHandler(null);

        $captured = null;
        $e = $this->catch(fn () => (new CsrfMiddleware(expiredSessionHandler: $handler))->process($request, $this->createHandler($captured)));

        $this->assertInstanceOf(CsrfSessionExpiredException::class, $e);
        $this->assertCount(1, $handler->calls);
        $this->assertNull($captured);
    }

    public function test_handler_is_not_called_for_any_other_failure_or_pass(): void
    {
        $handler = $this->expiredHandler(new Response(303));
        $middleware = new CsrfMiddleware(['/--component/'], $handler);

        // mismatch on a live session
        [$request, $session] = $this->createRequestWithSession('POST');
        CsrfToken::generate($session);
        $this->catch(fn () => $middleware->process($request->withParsedBody(['_csrf_token' => 'forged']), $this->createHandler()));

        // missing token on a new session
        [$request] = $this->createRequestWithSession('POST');
        $this->catch(fn () => $middleware->process($request, $this->createHandler()));

        // safe method with a token on a new session
        [$request] = $this->createRequestWithSession('GET');
        $this->assertSame(200, $middleware->process($request->withHeader('X-CSRF-Token', 'old'), $this->createHandler())->getStatusCode());

        // exempt path with a token on a new session
        [$request] = $this->createRequestWithSession('POST', '/--component/render');
        $this->assertSame(200, $middleware->process($request->withParsedBody(['_csrf_token' => 'old']), $this->createHandler())->getStatusCode());

        // valid token
        [$request, $session] = $this->createRequestWithSession('POST');
        $valid = CsrfToken::generate($session)->getValue();
        $this->assertSame(200, $middleware->process($request->withParsedBody(['_csrf_token' => $valid]), $this->createHandler())->getStatusCode());

        $this->assertSame([], $handler->calls);
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
