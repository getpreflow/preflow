<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

final class TestResponse
{
    private readonly string $body;

    public function __construct(
        private readonly ResponseInterface $response,
    ) {
        $this->body = (string) $response->getBody();
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function assertOk(): self
    {
        Assert::assertSame(200, $this->status(), "Expected status 200, got {$this->status()}.");
        return $this;
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status(), "Expected status {$status}, got {$this->status()}.");
        return $this;
    }

    public function assertRedirect(string $uri): self
    {
        Assert::assertTrue(
            $this->status() >= 300 && $this->status() < 400,
            "Expected redirect status, got {$this->status()}."
        );
        Assert::assertSame($uri, $this->response->getHeaderLine('Location'));
        return $this;
    }

    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->body, "Failed asserting response contains '{$text}'.");
        return $this;
    }

    public function assertNotSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->body, "Response should not contain '{$text}'.");
        return $this;
    }

    public function assertHeader(string $name, string $value): self
    {
        Assert::assertSame($value, $this->response->getHeaderLine($name));
        return $this;
    }

    public function assertHeaderContains(string $name, string $substring): self
    {
        Assert::assertStringContainsString(
            $substring,
            $this->response->getHeaderLine($name),
            "Header '{$name}' does not contain '{$substring}'."
        );
        return $this;
    }

    public function assertJson(): self
    {
        Assert::assertStringContainsString('json', $this->response->getHeaderLine('Content-Type'));
        json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        return $this;
    }

    /**
     * The decoded JSON body, or the value at a dot path (`data.0.title`). A missing path or a body
     * that is not JSON fails the test.
     */
    public function json(?string $path = null): mixed
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Assert::fail('Response body is not valid JSON: ' . $e->getMessage());
        }

        if ($path === null || $path === '') {
            return $decoded;
        }

        $value = $decoded;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                Assert::fail("JSON path '{$path}' does not exist in the response.");
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertSame($expected, $this->json($path), "Failed asserting JSON path '{$path}' has the expected value.");
        return $this;
    }

    /** Status 422 with every field listed under `errors`. */
    public function assertJsonValidationErrors(string ...$fields): self
    {
        $this->assertStatus(422);
        $errors = $this->json('errors');
        Assert::assertIsArray($errors, "Expected 'errors' to be an object.");
        foreach ($fields as $field) {
            Assert::assertArrayHasKey($field, $errors, "Expected a validation error for '{$field}'.");
        }
        return $this;
    }

    public function assertMethodNotAllowed(): self
    {
        return $this->assertStatus(405);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }
}
