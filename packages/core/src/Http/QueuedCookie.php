<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

/**
 * A Set-Cookie value a render left queued, as an item of its frame's outbox (channel 'cookies'):
 * a render that captures reports it, a nested render that delivers hands it to the frame around
 * it, and the owner of the request sets it on the response.
 *
 * @since 0.81.0
 */
final readonly class QueuedCookie
{
    /** @param string $header the Set-Cookie header value ('name=value; Path=/; …') */
    public function __construct(public string $header) {}

    /** The cookie's name, without its value (which may be a secret, such as a remember-me token). */
    public function name(): string
    {
        $name = strstr($this->header, '=', true);

        return trim($name === false ? $this->header : $name);
    }
}
