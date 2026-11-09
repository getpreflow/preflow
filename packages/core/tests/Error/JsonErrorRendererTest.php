<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Error;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Error\JsonErrorRenderer;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

final class JsonErrorRendererTest extends TestCase
{
    private function render(\Throwable $e, int $status, bool $debug = false): string
    {
        return (new JsonErrorRenderer($debug))->render($e, (new Psr17Factory())->createServerRequest('GET', '/api/x'), $status);
    }

    public function test_an_http_exception_shows_its_message(): void
    {
        $this->assertSame('{"message":"Job 7 not found"}', $this->render(new NotFoundHttpException('Job 7 not found'), 404));
    }

    public function test_other_exceptions_hide_their_message(): void
    {
        $body = $this->render(new \RuntimeException('db password x'), 500);

        $this->assertSame('{"message":"Server Error"}', $body);
        $this->assertStringNotContainsString('db password', $body);
    }

    public function test_debug_adds_the_exception(): void
    {
        $data = json_decode($this->render(new \RuntimeException('db password x'), 500, true), true);

        $this->assertSame('Server Error', $data['message']);
        $this->assertSame(\RuntimeException::class, $data['debug']['exception']);
        $this->assertSame('db password x', $data['debug']['message']);
        $this->assertArrayHasKey('file', $data['debug']);
        $this->assertArrayHasKey('line', $data['debug']);
    }

    public function test_validation_failure_lists_the_errors(): void
    {
        $e = new ValidationException(new ValidationResult(['title' => ['The title is required.']]));

        $this->assertSame(
            '{"message":"The given data was invalid.","errors":{"title":["The title is required."]}}',
            $this->render($e, 422),
        );
    }
}
