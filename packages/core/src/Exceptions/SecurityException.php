<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

final class SecurityException extends ForbiddenHttpException
{
    public function __construct(string $message = 'Security violation', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
