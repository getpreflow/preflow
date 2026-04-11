<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Error;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Core\Error\DevErrorRenderer;
use Nyholm\Psr7\ServerRequest;

final class DevErrorRendererTest extends TestCase
{
    public function test_renders_exception_class_and_message(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \InvalidArgumentException('Something went wrong');
        $request = new ServerRequest('GET', '/test');
        $html = $renderer->render($exception, $request, 500);
        $this->assertStringContainsString('InvalidArgumentException', $html);
        $this->assertStringContainsString('Something went wrong', $html);
    }

    public function test_renders_file_and_line(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('Test');
        $request = new ServerRequest('GET', '/test');
        $html = $renderer->render($exception, $request, 500);
        $this->assertStringContainsString(__FILE__, $html);
    }

    public function test_renders_source_context(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('Source test');
        $request = new ServerRequest('GET', '/test');
        $html = $renderer->render($exception, $request, 500);
        $this->assertStringContainsString('Source test', $html);
        $this->assertMatchesRegularExpression('/\d+/', $html);
    }

    public function test_renders_stack_trace(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('Trace test');
        $request = new ServerRequest('GET', '/test');
        $html = $renderer->render($exception, $request, 500);
        $this->assertStringContainsString('STACK TRACE', $html);
    }

    public function test_renders_request_info(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('Request test');
        $request = new ServerRequest('POST', '/api/posts?page=2');
        $html = $renderer->render($exception, $request, 500);
        $this->assertStringContainsString('POST', $html);
        $this->assertStringContainsString('/api/posts', $html);
    }

    public function test_renders_debug_context_when_collector_provided(): void
    {
        $collector = new DebugCollector();
        $collector->logQuery('SELECT * FROM posts', ['published'], 2.5, 'sqlite');
        $collector->logComponent('App\\Counter', 'Counter-abc', 3.2);
        $collector->setRoute('component', 'pages/blog.twig', []);
        $renderer = new DevErrorRenderer($collector);
        $exception = new \RuntimeException('Debug test');
        $request = new ServerRequest('GET', '/blog');
        $html = $renderer->render($exception, $request, 500);
        $this->assertStringContainsString('SELECT * FROM posts', $html);
        $this->assertStringContainsString('App\\Counter', $html);
        $this->assertStringContainsString('pages/blog.twig', $html);
    }

    public function test_omits_debug_context_without_collector(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('No debug');
        $request = new ServerRequest('GET', '/test');
        $html = $renderer->render($exception, $request, 500);
        $this->assertStringNotContainsString('QUERIES', $html);
        $this->assertStringNotContainsString('COMPONENTS', $html);
    }

    public function test_escapes_html_in_message(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('<script>alert(1)</script>');
        $request = new ServerRequest('GET', '/test');
        $html = $renderer->render($exception, $request, 500);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_returns_valid_html_document(): void
    {
        $renderer = new DevErrorRenderer();
        $exception = new \RuntimeException('HTML test');
        $request = new ServerRequest('GET', '/test');
        $html = $renderer->render($exception, $request, 500);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }
}
