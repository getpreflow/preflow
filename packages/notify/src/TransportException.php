<?php

declare(strict_types=1);

namespace Preflow\Notify;

final class TransportException extends \RuntimeException
{
    public static function fromProvider(string $transport, \Throwable $previous): self
    {
        return new self(
            sprintf('Transport "%s" failed: %s', $transport, self::maskCredentials($previous->getMessage())),
            0,
            $previous,
        );
    }

    /** Replaces the user-info part of any URL/DSN ("smtp://user:pass@host") with "***". */
    public static function maskCredentials(string $text): string
    {
        return (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^@/\s]+@#i', '$1***@', $text);
    }
}
