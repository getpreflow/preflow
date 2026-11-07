<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Emitter
{
    /** @var \Closure(string, bool, int=): void */
    private \Closure $send;

    /**
     * @param (\Closure(string, bool, int=): void)|null $send header() replacement (tests); a "!name" line removes a header
     */
    public function __construct(?\Closure $send = null)
    {
        $this->send = $send ?? static function (string $line, bool $replace, int $code = 0): void {
            if (str_starts_with($line, '!')) {
                header_remove(substr($line, 1));
            } elseif ($code > 0) {
                header($line, $replace, $code);
            } else {
                header($line, $replace);
            }
        };
    }

    public function emit(ResponseInterface $response, ?ServerRequestInterface $request = null): void
    {
        if (headers_sent()) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();
        // The status line answers the request's HTTP version (an HTTP/1.0 client gets HTTP/1.0).
        $protocol = $request !== null ? $request->getProtocolVersion() : $response->getProtocolVersion();
        $isHead = $request !== null && strtoupper($request->getMethod()) === 'HEAD';
        $body = $response->getBody();
        $noBody = $statusCode === 204 || $statusCode === 304 || ($statusCode >= 100 && $statusCode < 200);
        $size = $body->getSize();

        ($this->send)("HTTP/{$protocol} {$statusCode} {$reasonPhrase}", true, $statusCode);

        foreach ($response->getHeaders() as $name => $values) {
            // Set-Cookie headers must never replace — multiple cookies are normal
            // (session, locale, CSRF, etc.). Other headers replace the first value.
            $replace = strtolower($name) !== 'set-cookie';
            foreach ($values as $value) {
                ($this->send)("{$name}: {$value}", $replace);
                $replace = false;
            }
        }

        if ($noBody) {
            // 204 and 304 carry neither a body nor a length.
            ($this->send)('!Content-Length', true);
            return;
        }

        if (!$response->hasHeader('Content-Length') && !$response->hasHeader('Transfer-Encoding')) {
            if ($size !== null) {
                ($this->send)('Content-Length: ' . $size, true);
            } elseif ($protocol === '1.0') {
                // Unknown length (stream, download): HTTP/1.0 cannot chunk, so the connection ends the body.
                ($this->send)('Connection: close', true);
            }
        }

        if ($isHead) {
            return;
        }

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
