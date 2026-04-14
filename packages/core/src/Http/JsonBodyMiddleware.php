<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            if ($body !== '') {
                $parsed = json_decode($body, true);
                if (is_array($parsed)) {
                    $request = $request->withParsedBody($parsed);
                }
            }
        }

        return $handler->handle($request);
    }
}
