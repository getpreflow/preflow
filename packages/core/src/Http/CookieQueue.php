<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Set-Cookie values queued during a request (e.g. remember-me cookies of
 * preflow/auth). The middleware that queues them applies them to its response;
 * the Kernel applies what is left to an error response, which bypasses the
 * pipeline. Applying empties the queue.
 */
final class CookieQueue
{
    /** @var list<string> */
    private array $cookies = [];

    public function queue(string $setCookie): void
    {
        $this->cookies[] = $setCookie;
    }

    /** Whether a cookie named $name is queued (not applied yet). */
    public function has(string $name): bool
    {
        foreach ($this->cookies as $cookie) {
            if (str_starts_with($cookie, $name . '=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The queued values, emptying the queue.
     *
     * @return list<string>
     */
    public function pull(): array
    {
        [$cookies, $this->cookies] = [$this->cookies, []];

        return $cookies;
    }

    public function apply(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->pull() as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }

        return $response;
    }
}
