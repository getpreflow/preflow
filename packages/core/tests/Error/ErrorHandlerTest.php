<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Error;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\DevErrorRenderer;
use Preflow\Core\Error\ProdErrorRenderer;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Error\JsonErrorRenderer;
use Preflow\Core\Http\Api\ApiConfig;
use Preflow\Core\Http\InternalRender;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

final class ErrorHandlerTest extends TestCase
{
    private function createRequest(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/test');
    }

    public function test_handles_http_exception_with_correct_status(): void
    {
        $handler = new ErrorHandler(new ProdErrorRenderer());
        $response = $handler->handleException(
            new NotFoundHttpException('Page not found'),
            $this->createRequest(),
        );
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_handles_generic_exception_as_500(): void
    {
        $handler = new ErrorHandler(new ProdErrorRenderer());
        $response = $handler->handleException(
            new \RuntimeException('Something broke'),
            $this->createRequest(),
        );
        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_prod_renderer_hides_details(): void
    {
        $handler = new ErrorHandler(new ProdErrorRenderer());
        $response = $handler->handleException(
            new \RuntimeException('secret internal details'),
            $this->createRequest(),
        );
        $body = (string) $response->getBody();
        $this->assertStringNotContainsString('secret internal details', $body);
        $this->assertStringContainsString('Internal Server Error', $body);
    }

    public function test_dev_renderer_shows_details(): void
    {
        $handler = new ErrorHandler(new DevErrorRenderer());
        $response = $handler->handleException(
            new \RuntimeException('visible debug info'),
            $this->createRequest(),
        );
        $body = (string) $response->getBody();
        $this->assertStringContainsString('visible debug info', $body);
        $this->assertStringContainsString('RuntimeException', $body);
    }

    public function test_dev_renderer_shows_stack_trace(): void
    {
        $handler = new ErrorHandler(new DevErrorRenderer());
        $response = $handler->handleException(
            new \RuntimeException('trace test'),
            $this->createRequest(),
        );
        $body = (string) $response->getBody();
        $this->assertStringContainsString('ErrorHandlerTest.php', $body);
    }

    public function test_http_exception_message_shown_in_prod(): void
    {
        $handler = new ErrorHandler(new ProdErrorRenderer());
        $response = $handler->handleException(
            new HttpException(400, 'The request body is not valid JSON.'),
            $this->createRequest(),
        );
        $body = (string) $response->getBody();
        $this->assertStringContainsString('The request body is not valid JSON.', $body);
    }

    public function test_prod_404_and_405_show_only_the_status_text(): void
    {
        $handler = new ErrorHandler(new ProdErrorRenderer());

        $notFound = (string) $handler->handleException(new NotFoundHttpException('No route matches [GET /x]'), $this->createRequest())->getBody();
        $notAllowed = (string) $handler->handleException(
            new \Preflow\Core\Exceptions\MethodNotAllowedHttpException(['POST'], 'GET not allowed on /x'),
            $this->createRequest(),
        )->getBody();

        $this->assertStringNotContainsString('No route matches', $notFound);
        $this->assertStringContainsString('Not Found', $notFound);
        $this->assertStringNotContainsString('not allowed on', $notAllowed);
        $this->assertStringContainsString('Method Not Allowed', $notAllowed);
    }

    public function test_an_internal_render_gets_the_internal_renderers_page(): void
    {
        $handler = new ErrorHandler(new DevErrorRenderer(), new ProdErrorRenderer());

        $internal = $handler->handleException(new \RuntimeException('debug only detail'), InternalRender::mark($this->createRequest()));
        $visitor = $handler->handleException(new \RuntimeException('debug only detail'), $this->createRequest());
        $notFound = $handler->handleException(new NotFoundHttpException('Gone'), InternalRender::mark($this->createRequest()));

        $this->assertSame(500, $internal->getStatusCode());
        $this->assertStringNotContainsString('debug only detail', (string) $internal->getBody());
        $this->assertStringContainsString('Internal Server Error', (string) $internal->getBody());
        $this->assertStringContainsString('debug only detail', (string) $visitor->getBody());
        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertStringContainsString('Not Found', (string) $notFound->getBody());
    }

    public function test_without_an_internal_renderer_an_internal_render_gets_the_usual_page(): void
    {
        $handler = new ErrorHandler(new DevErrorRenderer());

        $response = $handler->handleException(new \RuntimeException('shown as usual'), InternalRender::mark($this->createRequest()));

        $this->assertStringContainsString('shown as usual', (string) $response->getBody());
    }

    private function apiHandler(): ErrorHandler
    {
        return new ErrorHandler(new ProdErrorRenderer(), null, new ApiConfig(), new JsonErrorRenderer(false));
    }

    private function htmlRequest(string $path): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', $path)->withHeader('Accept', 'text/html,*/*');
    }

    public function test_api_request_gets_json_error(): void
    {
        $response = $this->apiHandler()->handleException(
            new NotFoundHttpException('Nope'),
            (new Psr17Factory())->createServerRequest('GET', '/api/x'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"message":"Nope"}', (string) $response->getBody());
    }

    public function test_html_request_keeps_html_error(): void
    {
        $response = $this->apiHandler()->handleException(new NotFoundHttpException(), $this->htmlRequest('/x'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
    }

    public function test_validation_exception_is_422(): void
    {
        $e = new ValidationException(new ValidationResult(['title' => ['The title is required.']]));

        $api = $this->apiHandler()->handleException($e, (new Psr17Factory())->createServerRequest('POST', '/api/jobs'));
        $html = $this->apiHandler()->handleException($e, $this->htmlRequest('/jobs'));

        $this->assertSame(422, $api->getStatusCode());
        $this->assertStringContainsString('"errors":{"title":["The title is required."]}', (string) $api->getBody());
        $this->assertSame(422, $html->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $html->getHeaderLine('Content-Type'));
    }

    public function test_http_exception_headers_reach_the_response(): void
    {
        $e = new HttpException(401, 'Unauthorized', null, ['WWW-Authenticate' => 'Bearer']);

        $api = $this->apiHandler()->handleException($e, (new Psr17Factory())->createServerRequest('GET', '/api/x'));
        $html = $this->apiHandler()->handleException($e, $this->htmlRequest('/x'));

        $this->assertSame('Bearer', $api->getHeaderLine('WWW-Authenticate'));
        $this->assertSame('Bearer', $html->getHeaderLine('WWW-Authenticate'));
    }

    public function test_internal_render_keeps_internal_renderer(): void
    {
        $handler = new ErrorHandler(new DevErrorRenderer(), new ProdErrorRenderer(), new ApiConfig(), new JsonErrorRenderer(true));

        $response = $handler->handleException(
            new \RuntimeException('debug only detail'),
            InternalRender::mark((new Psr17Factory())->createServerRequest('GET', '/api/x')),
        );

        $this->assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringNotContainsString('debug only detail', (string) $response->getBody());
    }
}
