<?php

declare(strict_types=1);

namespace Preflow\Core\Security;

/**
 * The one rule for whether app.key may sign something a visitor could otherwise forge: trimmed of
 * surrounding whitespace, it is at least MIN_LENGTH bytes long and not a key anyone knows
 * (PUBLIC_KEYS: the built-in default and the skeleton's example). A short key can be found
 * offline from any MAC a visitor sees; a public one needs no finding.
 *
 * Signers that follow it: preview tokens (Context\Preview\PreviewToken), share-preview links
 * (publishing's PreviewLinks) and the page cache's signed placeholders (PageCache\LivePlaceholder,
 * PageCache\PerRequestValues). They switch their signing off with a key that fails it. A web
 * request never gets that far in production: Application::boot() refuses such a key there
 * (WeakAppKey).
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
        return self::problem($key) === null;
    }

    /**
     * What is wrong with $key, in words for the person who runs the site (never the key itself);
     * null when it can sign.
     *
     * @since 0.81.0
     */
    public static function problem(#[\SensitiveParameter] string $key): ?string
    {
        $trimmed = trim($key);

        return match (true) {
            $trimmed === '' => 'app.key is empty',
            in_array($trimmed, self::PUBLIC_KEYS, true) => 'app.key is a public example key that anyone can look up',
            strlen($trimmed) < self::MIN_LENGTH => 'app.key is ' . strlen($trimmed) . ' bytes long; it needs at least ' . self::MIN_LENGTH,
            default => null,
        };
    }
}
