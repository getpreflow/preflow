<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Nyholm\Psr7\Response;
use Preflow\Testing\RouteTestCase;
use Preflow\Testing\TestResponse;

final class RouteTestCaseTest extends RouteTestCase
{
    public function test_get_returns_test_response(): void
    {
        $response = $this->createTestResponse(new Response(200, [], 'Hello'));

        $this->assertInstanceOf(TestResponse::class, $response);
    }

    public function test_assert_ok(): void
    {
        $response = $this->createTestResponse(new Response(200));

        $response->assertOk();
    }

    public function test_assert_status(): void
    {
        $response = $this->createTestResponse(new Response(404));

        $response->assertStatus(404);
    }

    public function test_assert_redirect(): void
    {
        $response = $this->createTestResponse(new Response(302, ['Location' => '/login']));

        $response->assertRedirect('/login');
    }

    public function test_assert_see(): void
    {
        $response = $this->createTestResponse(new Response(200, [], '<h1>Welcome</h1>'));

        $response->assertSee('Welcome');
    }

    public function test_assert_not_see(): void
    {
        $response = $this->createTestResponse(new Response(200, [], '<h1>Welcome</h1>'));

        $response->assertNotSee('Goodbye');
    }

    public function test_assert_header(): void
    {
        $response = $this->createTestResponse(
            new Response(200, ['X-Custom' => 'value'])
        );

        $response->assertHeader('X-Custom', 'value');
    }

    public function test_assert_header_contains(): void
    {
        $response = $this->createTestResponse(
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'])
        );

        $response->assertHeaderContains('Content-Type', 'text/html');
    }

    public function test_assert_json(): void
    {
        $response = $this->createTestResponse(
            new Response(200, ['Content-Type' => 'application/json'], '{"key":"value"}')
        );

        $response->assertJson();
    }

    public function test_body_access(): void
    {
        $response = $this->createTestResponse(new Response(200, [], 'body content'));

        $this->assertSame('body content', $response->body());
    }
}
