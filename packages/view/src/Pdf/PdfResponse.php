<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends PDF bytes to the browser, shown in place or saved as a file.
 */
final class PdfResponse
{
    public static function inline(string $bytes, string $fileName): ResponseInterface
    {
        return self::make($bytes, 'inline', $fileName);
    }

    public static function download(string $bytes, string $fileName): ResponseInterface
    {
        return self::make($bytes, 'attachment', $fileName);
    }

    private static function make(string $bytes, string $disposition, string $fileName): ResponseInterface
    {
        // Line breaks would split the header; the plain filename is ASCII for old clients, filename* carries the real name.
        $fileName = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $fileName);
        $ascii = (string) (preg_replace('/[^\x20-\x7E]|["\\\\]/u', '_', $fileName)
            ?? preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $fileName));

        return new Response(200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $ascii, rawurlencode($fileName)),
            'Cache-Control' => 'private, no-store',
        ], $bytes);
    }
}
