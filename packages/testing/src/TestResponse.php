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
