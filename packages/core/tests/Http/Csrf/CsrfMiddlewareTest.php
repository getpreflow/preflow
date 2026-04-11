<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Csrf;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Http\Csrf\CsrfMiddleware;
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
}
