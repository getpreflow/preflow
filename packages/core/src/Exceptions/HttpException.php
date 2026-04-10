<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
