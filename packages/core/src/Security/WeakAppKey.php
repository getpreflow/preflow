<?php

declare(strict_types=1);

namespace Preflow\Core\Security;

/**
 * The app refuses to serve web requests in production with an app.key that cannot sign
 * (AppKey::canSign()): what is wrong, and the fix. Console commands still boot, so the site can
 * fix itself with `php preflow key:generate`.
 *
 * @since 0.81.0
 */
final class WeakAppKey extends \RuntimeException
{
    public static function because(string $problem): self
    {
        return new self(
            "The app does not start: {$problem}. Run `php preflow key:generate` (it writes a new APP_KEY to .env), "
            . 'then deploy again. Everyone is signed out once, and signed links made with the old key '
            . '(share-preview links, password links) stop working.',
        );
    }
}
