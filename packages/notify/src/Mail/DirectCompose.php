<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/**
 * Composes where the Mailer is called: the layout gets the language as its lang variable ('en'
 * when none is given) and nothing else changes (notify's default, as before 0.81).
 *
 * @since 0.81.0
 */
final class DirectCompose implements ComposeScope
{
    public function run(?string $lang, \Closure $compose): mixed
    {
        return $compose($lang ?? 'en');
    }
}
