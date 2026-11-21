<?php

declare(strict_types=1);

namespace Preflow\Core\Security;

/**
 * The app refuses to serve web requests in production with an app.key that cannot sign
 * (AppKey::canSign()): what is wrong, and the fix. Console commands still boot, so the site can
 * fix itself with `php preflow key:generate` (preflow/devtools), or with a key made by PHP alone.
 * What a new key changes is in the message: sessions, remember-me sign-ins and password links do
 * not use the key; share-preview links, stateless CSRF tokens and component action tokens on open
 * pages, rate-limit client hashes and the signed placeholders of stored pages do.
 *
 * @since 0.81.0
 */
final class WeakAppKey extends \RuntimeException
{
    public static function because(string $problem): self
    {
        return new self(
            "The app does not start: {$problem}. Run `php preflow key:generate` (preflow/devtools; it writes a new "
            . "APP_KEY to .env), or set APP_KEY to the output of `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. "
            . 'Then clear the page cache (`php preflow cache:clear pages`, or delete storage/cache/pages) and deploy again. '
            . 'Sessions, remember-me sign-ins and password links stay valid. Share-preview links stop working, '
            . 'forms and component actions on pages that are already open fail until they are reloaded, '
            . 'and rate-limit counts (sign-ins, AI limits) start again.',
        );
    }
}
