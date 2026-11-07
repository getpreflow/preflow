<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Folio\Users\UserPreferences;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST {prefix}/_prefs/nav (the sidebar's section headings): stores whether one section is open
 * (open=1) or closed (open=0) for the signed-in user, 204 without a body. Any signed-in user,
 * without a permission (FolioPermissionMiddleware::SIGNED_IN); signed out goes to the login.
 * A section name outside [a-z][a-z0-9_-]{0,63} or a flag other than 0/1 is refused (422), nothing stored.
 * The CSRF middleware runs before it, as for every admin POST.
 */
final class NavPreferenceController
{
    public function __construct(
        private readonly UserPreferences $preferences,
        private readonly string $prefix,
    ) {}

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(Authenticatable::class);
        if (!$user instanceof Authenticatable) {
            return new Response(302, ['Location' => rtrim($this->prefix, '/') . '/login']);
        }
        $body = (array) $request->getParsedBody();
        $section = $body['section'] ?? null;
        $open = $body['open'] ?? null;
        if (!is_string($section) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $section) !== 1 || ($open !== '0' && $open !== '1')) {
            return new Response(422, ['Content-Type' => 'text/plain; charset=UTF-8'], 'Invalid section or flag.');
        }

        $this->preferences->setNavSection($user->getAuthId(), $section, $open === '1');

        return new Response(204);
    }
}
