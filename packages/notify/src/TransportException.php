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

    /**
     * Masks credentials in URLs/DSNs: the user-info part ("smtp://user:p@ss@host"
     * → "smtp://***@host", up to the last "@" before the host) and secret-looking
     * query parameters ("?api_key=…", "password=…", "token=…").
     */
    public static function maskCredentials(string $text): string
    {
        $text = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^\s/"\']*@#i', '$1***@', $text);

        return (string) preg_replace(
            '#\b((?:pass(?:word)?|pwd|secret|token|access[_-]?token|auth[_-]?token|api[_-]?key|key))=[^&\s"\']+#i',
            '$1=***',
            $text,
        );
    }
}
