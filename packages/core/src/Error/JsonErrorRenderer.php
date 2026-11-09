<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Preflow\Core\Exceptions\HttpException;
use Preflow\Core\Http\Json;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The error body of an API: {"message": ...}, plus "errors" for a 422 and "debug" in debug mode.
 */
final class JsonErrorRenderer implements ErrorRendererInterface
{
    private const STATUS_MESSAGES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        410 => 'Gone',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        422 => 'The given data was invalid.',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public function __construct(
        private readonly bool $debug = false,
    ) {}

    public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string
    {
        $validation = $exception instanceof \Preflow\Validation\ValidationException;

        // An HttpException's message is written for the client; any other message may leak internals.
        if ($validation) {
            $message = self::STATUS_MESSAGES[422];
        } elseif ($exception instanceof HttpException && $exception->getMessage() !== '') {
            $message = $exception->getMessage();
        } else {
            $message = self::STATUS_MESSAGES[$statusCode] ?? ($statusCode >= 400 && $statusCode < 500 ? 'Client Error' : 'Server Error');
        }

        $body = ['message' => $message];

        if ($validation) {
            $body['errors'] = $exception->errors();
        }

        if ($this->debug) {
            $body['debug'] = [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        return json_encode($body, Json::FLAGS | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
