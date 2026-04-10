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
            new NotFoundHttpException('The page you requested was not found'),
            $this->createRequest(),
        );
        $body = (string) $response->getBody();
        $this->assertStringContainsString('The page you requested was not found', $body);
    }
}
