<?php

declare(strict_types=1);

namespace Preflow\Media\Access;

use Nyholm\Psr7\Response;
use Preflow\Media\Asset;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The core's default: nobody downloads a private file (403) until something binds its own rules. */
final class DenyAllPrivateFileAccess implements PrivateFileAccess
{
    public function decide(ServerRequestInterface $request, Asset $asset): ?ResponseInterface
    {
        return new Response(403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store'], 'Forbidden');
    }
}
