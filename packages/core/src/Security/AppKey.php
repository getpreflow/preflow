<?php

declare(strict_types=1);

namespace Preflow\Core\Security;

/**
 * The one rule for whether app.key may sign something a visitor could otherwise forge: trimmed of
 * surrounding whitespace, it is at least MIN_LENGTH bytes long and not a key anyone knows
 * (PUBLIC_KEYS: the built-in default and the skeleton's example). A short key can be found
 * offline from any MAC a visitor sees; a public one needs no finding.
 *
 * Signers that follow it: preview tokens (Context\Preview\PreviewToken) and the page cache's
 * signed placeholders (PageCache\LivePlaceholder, PageCache\PerRequestValues). They switch their
 * signing off with a key that fails it.
 *
 * @since 0.81.0
 */
final class AppKey
{
    /** The shortest key that signs, in bytes (after trimming). */
    public const int MIN_LENGTH = 32;

    /** Keys anyone knows: empty, the built-in default (StatelessCsrfToken::DEFAULT_KEY) and the skeleton's example (.env.example). */
    public const array PUBLIC_KEYS = ['', \Preflow\Core\Http\Csrf\StatelessCsrfToken::DEFAULT_KEY, 'change-this-to-a-random-32-char-string!'];

    /** Whether $key may sign (see the class docblock). */
    public static function canSign(#[\SensitiveParameter] string $key): bool
    {
        $trimmed = trim($key);

        return strlen($trimmed) >= self::MIN_LENGTH && !in_array($trimmed, self::PUBLIC_KEYS, true);
    }
}
