<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;

final class Emitter
{
    public function emit(ResponseInterface $response): void
    {
        if (headers_sent()) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();
        $protocol = $response->getProtocolVersion();

        header(
            "HTTP/{$protocol} {$statusCode} {$reasonPhrase}",
            true,
            $statusCode,
        );

        foreach ($response->getHeaders() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header("{$name}: {$value}", $first);
                $first = false;
            }
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(8192);

            if (connection_aborted()) {
                break;
            }
        }
    }
}
