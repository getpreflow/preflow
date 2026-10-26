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
 * Folio's rules for the media core's private files: a private file and its preview only
 * for a signed-in user with assets.private_permission, or with assets.view, so staff who
 * can see the library open what it shows. The earlier files of any asset (a request with
 * PrivateFileAccess::VERSION) are staff only: assets.view, never the members' permission.
 * FrontendGate fails closed (nobody without auth configured). Anyone else gets a 403, or
 * (not for a version) goes to assets.private_login with the URL as "next". Both settings come from
 * config/folio.php "assets".
 */
final class FolioPrivateFileAccess implements PrivateFileAccess
{
    private const string NO_STORE = 'private, no-store';

    /** Who sees the asset library opens its files too. */
    private const string LIBRARY = 'assets.view';

    public function __construct(
        private readonly FrontendGate $gate,
        private readonly string $permission,
        private readonly ?string $login = null,
    ) {}

    public function decide(ServerRequestInterface $request, Asset $asset): ?ResponseInterface
    {
        $version = $request->getAttribute(PrivateFileAccess::VERSION) !== null;
        if ($this->gate->allows(self::LIBRARY) || (!$version && $this->gate->allows($this->permission))) {
            return null;
        }
        // private_login is the members' sign-in, which never opens versions: a plain 403 for those.
        if ($this->login === null || $version) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => self::NO_STORE], 'Forbidden');
        }
        $next = '/' . ltrim($request->getUri()->getPath(), '/\\');

        return new Response(302, [
            'Location' => $this->login . (str_contains($this->login, '?') ? '&' : '?') . 'next=' . rawurlencode($next),
            'Cache-Control' => self::NO_STORE,
        ]);
    }
}
