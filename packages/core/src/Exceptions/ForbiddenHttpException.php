<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

class ForbiddenHttpException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, $previous);
    }
}
