<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
        /** @var array<string, string> sent with the error response, e.g. WWW-Authenticate or Allow */
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
