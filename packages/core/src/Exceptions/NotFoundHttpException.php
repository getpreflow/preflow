<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

final class NotFoundHttpException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?\Throwable $previous = null, array $headers = [])
    {
        parent::__construct(404, $message, $previous, $headers);
    }
}
