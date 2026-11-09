<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

final class MethodNotAllowedHttpException extends HttpException
{
    /** @var list<string> */
    public readonly array $allowed;

    /**
     * @param list<string> $allowed the methods the path answers to; sent as the Allow header
     */
    public function __construct(array $allowed, string $message = 'Method Not Allowed', ?\Throwable $previous = null)
    {
        $allowed = array_values(array_unique($allowed));
        sort($allowed);
        $this->allowed = $allowed;

        parent::__construct(405, $message, $previous, ['Allow' => implode(', ', $allowed)]);
    }
}
