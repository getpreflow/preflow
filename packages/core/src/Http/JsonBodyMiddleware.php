<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Http\Api\ApiConfig;
use Preflow\Core\Exceptions\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JsonBodyMiddleware implements MiddlewareInterface
{
    private const CHUNK = 8192;

    /**
     * @param ?int $maxBody largest JSON body in bytes on paths outside an API prefix; null = no limit (0.78 behaviour)
     * @param ?ApiConfig $api where an API prefix's own max_body is read from
     * @since 0.79.0 (both parameters)
     */
    public function __construct(
        private readonly ?int $maxBody = null,
        private readonly ?ApiConfig $api = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $body = $this->read($request);
            if ($body !== '') {
                // A body that claims to be JSON but is not is the client's mistake: answer 400, don't guess.
                try {
                    $parsed = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new HttpException(400, 'The request body is not valid JSON.', $e);
                }

                if (is_array($parsed)) {
                    $request = $request->withParsedBody($parsed);
                }
            }
        }

        return $handler->handle($request);
    }

    /** The body, or a 413 when it is over the limit; an oversized Content-Length is refused before any byte is read. */
    private function read(ServerRequestInterface $request): string
    {
        $limit = $this->limitFor($request);
        $stream = $request->getBody();

        if ($limit === null) {
            return (string) $stream;
        }

        $tooLarge = static fn (): HttpException => new HttpException(413, 'The request body is too large.');

        $declared = $request->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $limit) {
            throw $tooLarge();
        }
        $size = $stream->getSize();
        if ($size !== null && $size > $limit) {
            throw $tooLarge();
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(min(self::CHUNK, $limit + 1 - strlen($body)));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $limit) {
                throw $tooLarge();
            }
        }

        return $body;
    }

    private function limitFor(ServerRequestInterface $request): ?int
    {
        if ($this->api !== null && $this->api->isApi($request) && $this->api->maxBody !== null) {
            return $this->api->maxBody;
        }

        return $this->maxBody;
    }
}
