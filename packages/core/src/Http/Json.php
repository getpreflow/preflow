<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * One place that builds JSON responses, so every API answer is encoded the same way.
 */
final class Json
{
    /** Slashes and unicode stay readable, 1.0 stays 1.0, and a failed encode throws instead of returning false. */
    public const int FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<string, string|string[]> $headers
     * @throws \JsonException when the body cannot be encoded
     */
    public static function response(mixed $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            json_encode($body, self::FLAGS),
        );
    }

    /**
     * Success envelope: {"data": …}, with "meta" only when there is some.
     *
     * @param array<string, mixed> $meta
     * @param array<string, string|string[]> $headers
     */
    public static function data(mixed $data, array $meta = [], int $status = 200, array $headers = []): ResponseInterface
    {
        $body = ['data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return self::response($body, $status, $headers);
    }

    /** 204 has no body, so it also has no Content-Type. */
    public static function noContent(): ResponseInterface
    {
        return new Response(204);
    }
}
