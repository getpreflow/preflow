<?php

declare(strict_types=1);

namespace Preflow\Folio\Media;

use Nyholm\Psr7\Response;
use Preflow\Folio\Content\FrontendGate;
use Preflow\Media\Access\PrivateFileAccess;
use Preflow\Media\Asset;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Folio's rules for the media core's private files: only a signed-in user with
 * assets.private_permission (FrontendGate, fails closed: nobody without auth
 * configured). Anyone else gets a 403, or goes to assets.private_login with the
 * URL as "next". Both settings come from config/folio.php "assets".
 */
final class FolioPrivateFileAccess implements PrivateFileAccess
{
    private const string NO_STORE = 'private, no-store';

    public function __construct(
        private readonly FrontendGate $gate,
        private readonly string $permission,
        private readonly ?string $login = null,
    ) {}

    public function decide(ServerRequestInterface $request, Asset $asset): ?ResponseInterface
    {
        if ($this->gate->allows($this->permission)) {
            return null;
        }
        if ($this->login === null) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => self::NO_STORE], 'Forbidden');
        }
        $next = '/' . ltrim($request->getUri()->getPath(), '/\\');

        return new Response(302, [
            'Location' => $this->login . (str_contains($this->login, '?') ? '&' : '?') . 'next=' . rawurlencode($next),
            'Cache-Control' => self::NO_STORE,
        ]);
    }
}
