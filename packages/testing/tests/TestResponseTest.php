<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Preflow\Testing\TestResponse;

final class TestResponseTest extends TestCase
{
    private function json(array $body, int $status = 200): TestResponse
    {
        return new TestResponse(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function test_json_without_path_returns_the_whole_body(): void
    {
        $this->assertSame(['data' => [['title' => 'A']]], $this->json(['data' => [['title' => 'A']]])->json());
    }

    public function test_json_reads_a_dot_path(): void
    {
        $response = $this->json(['data' => [['title' => 'A'], ['title' => 'B']], 'meta' => ['page' => 1]]);

        $this->assertSame('B', $response->json('data.1.title'));
        $this->assertSame(1, $response->json('meta.page'));
    }

    public function test_json_path_can_hold_null_and_false(): void
    {
        $response = $this->json(['a' => null, 'b' => false]);

        $this->assertNull($response->json('a'));
        $this->assertFalse($response->json('b'));
    }

    public function test_json_fails_the_test_for_a_missing_path(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('data.5.title');

        $this->json(['data' => [['title' => 'A']]])->json('data.5.title');
    }

    public function test_json_fails_the_test_when_the_body_is_not_json(): void
    {
        $this->expectException(AssertionFailedError::class);

        (new TestResponse(new Response(200, [], '<html>')))->json();
    }

    public function test_assert_json_path_passes_and_chains(): void
    {
        $response = $this->json(['data' => ['id' => 7]]);

        $this->assertSame($response, $response->assertJsonPath('data.id', 7));
    }

    public function test_assert_json_path_fails_for_a_different_value(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->json(['data' => ['id' => 7]])->assertJsonPath('data.id', 8);
    }

    public function test_assert_json_path_is_strict_about_type(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->json(['data' => ['id' => 7]])->assertJsonPath('data.id', '7');
    }

    public function test_assert_json_validation_errors_passes(): void
    {
        $response = $this->json(['message' => 'Invalid', 'errors' => ['title' => ['Required'], 'email' => ['Bad']]], 422);

        $this->assertSame($response, $response->assertJsonValidationErrors('title', 'email'));
    }

    public function test_assert_json_validation_errors_fails_for_another_status(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->json(['errors' => ['title' => ['Required']]], 400)->assertJsonValidationErrors('title');
    }

    public function test_assert_json_validation_errors_fails_for_a_missing_field(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('email');

        $this->json(['errors' => ['title' => ['Required']]], 422)->assertJsonValidationErrors('title', 'email');
    }

    public function test_assert_method_not_allowed(): void
    {
        $response = new TestResponse(new Response(405, ['Allow' => 'GET']));

        $this->assertSame($response, $response->assertMethodNotAllowed());
    }

    public function test_assert_method_not_allowed_fails_for_404(): void
    {
        $this->expectException(AssertionFailedError::class);

        (new TestResponse(new Response(404)))->assertMethodNotAllowed();
    }
}
