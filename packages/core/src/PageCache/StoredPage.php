<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * A cached response: its body, the headers to send again, when it was rendered (createdAt,
 * microtime) and when it must be refreshed (expiresAt, null = only the safety max_age applies).
 * A stale page is still served, while a fresh one is built (next visit).
 */
final readonly class StoredPage
{
    /** @param array<string, list<string>> $headers name => values, as PSR-7 gives them */
    public function __construct(
        public string $body,
        public array $headers,
        public float $createdAt,
        public ?float $expiresAt = null,
        public bool $stale = false,
    ) {}

    public function withStale(bool $stale = true): self
    {
        return new self($this->body, $this->headers, $this->createdAt, $this->expiresAt, $stale);
    }
}
