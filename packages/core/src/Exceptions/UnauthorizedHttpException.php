<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

final class UnauthorizedHttpException extends HttpException
{
    public function __construct(string $message = 'Unauthorized', ?\Throwable $previous = null)
    {
        parent::__construct(401, $message, $previous);
    }
}
