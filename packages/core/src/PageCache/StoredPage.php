<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * A cached response: its body, the headers to send again, when it was rendered (createdAt,
 * microtime) and when it must be refreshed (expiresAt, null = only the safety max_age applies).
 * A stale page is still served, while a fresh one is built (next visit).
 *
 * $state is what middleware outside the page cache needs from the render to finish the response
 * (SEO's page meta), as PageCache::carry() captured it: name => a JSON value.
 */
final readonly class StoredPage
{
    /**
     * @param array<string, list<string>> $headers name => values, as PSR-7 gives them
     * @param array<string, mixed> $state name => a JSON value
     */
    public function __construct(
        public string $body,
        public array $headers,
        public float $createdAt,
        public ?float $expiresAt = null,
        public bool $stale = false,
        public array $state = [],
    ) {}

    /**
     * Whether the page is served as stale at $now: marked, past its expiresAt, or older than
     * $maxAge seconds (0: no maximum age).
     */
    public function staleAt(float $now, int $maxAge): bool
    {
        return $this->stale
            || ($this->expiresAt !== null && $this->expiresAt <= $now)
            || ($maxAge > 0 && $this->createdAt + $maxAge <= $now);
    }

    public function withStale(bool $stale = true): self
    {
        return new self($this->body, $this->headers, $this->createdAt, $this->expiresAt, $stale, $this->state);
    }
}
