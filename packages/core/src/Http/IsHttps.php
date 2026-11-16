<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/** Whether a request came in over https: the URI scheme, or X-Forwarded-Proto from a TLS-terminating proxy. */
final class IsHttps
{
    public static function request(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getScheme() === 'https'
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
