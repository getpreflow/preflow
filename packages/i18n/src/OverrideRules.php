<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * What an override text must satisfy before it is stored, whoever writes it: a two-letter locale
 * the scope offers for the key's group, a key the scope offers, plain text of at most MAX_LENGTH
 * characters, and, where the scope names placeholders (a mail's :link), exactly those. Without a
 * scope only the locale, length and plain-text rules apply.
 */
final class OverrideRules
{
    public const string NOT_OFFERED = 'not_offered';
    public const string NOT_EDITABLE = 'not_editable';
    public const string TOO_LONG = 'too_long';
    public const string NO_MARKUP = 'no_markup';
    public const string PLACEHOLDER_MISSING = 'placeholder_missing';
    public const string PLACEHOLDER_UNKNOWN = 'placeholder_unknown';

    /** The longest override text, in characters. */
    public const int MAX_LENGTH = 2000;

    /** A placeholder in a text: ":name". */
    private const string PLACEHOLDER = '/:([A-Za-z_][A-Za-z0-9_]*)/';

    public function __construct(
        private readonly ?OverrideScope $scope = null,
    ) {}

    /**
     * Why $text (null: remove the override) may not be stored for $key in $locale, or null when it may.
     */
    public function check(string $locale, string $key, ?string $text): ?OverrideRefusal
    {
        $group = (string) strstr($key, '.', true);
        if (!self::validLocale($locale) || ($this->scope !== null && !in_array($locale, $this->scope->locales($group), true))) {
            return new OverrideRefusal(self::NOT_OFFERED);
        }
        if ($this->scope !== null && !$this->scope->editable($key, $locale)) {
            return new OverrideRefusal(self::NOT_EDITABLE);
        }
        if ($text === null || $text === '') {
            return null;
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            return new OverrideRefusal(self::TOO_LONG, ['max' => self::MAX_LENGTH]);
        }
        // Plain text only: it is printed unescaped, also inside HTML attributes.
        $text = self::validText($text);
        if ($text === null) {
            return new OverrideRefusal(self::NO_MARKUP);
        }
        $needed = $this->scope?->placeholders($key, $locale);
        if ($needed !== null) {
            // Its texts are filled in by code (a mail's :link): the same placeholders, no more, no less.
            $used = self::placeholdersIn($text);
            foreach (array_diff($needed, $used) as $name) {
                return new OverrideRefusal(self::PLACEHOLDER_MISSING, ['placeholder' => ':' . $name]);
            }
            foreach (array_diff($used, $needed) as $name) {
                return new OverrideRefusal(self::PLACEHOLDER_UNKNOWN, ['placeholder' => ':' . $name]);
            }
        }

        return null;
    }

    /**
     * An override text as it may be stored and shown, or null when it may not: plain text that
     * is safe anywhere a text is printed unescaped, also inside a double-quoted HTML attribute
     * (the translator's output is marked safe). No `<`, `>`, `"` or backtick, nothing shaped like
     * an HTML entity (`&#…`, `&name;`; a plain `&` is fine), at most
     * MAX_LENGTH characters, not empty; a straight apostrophe becomes the typographic one (’).
     */
    public static function validText(string $text): ?string
    {
        if (trim($text) === '' || mb_strlen($text) > self::MAX_LENGTH || strpbrk($text, '<>"`') !== false
            || str_contains($text, '&#') || preg_match('/&[a-z][a-z0-9]*;/i', $text) === 1) {
            return null;
        }

        return str_replace("'", '’', $text);
    }

    /** Two-letter locale codes only. */
    public static function validLocale(string $locale): bool
    {
        return preg_match('/^[a-z]{2}$/', $locale) === 1;
    }

    /**
     * The placeholder names in a text, unique, sorted.
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /**
     * A text in parts, in order: plain text and its placeholders (":name", placeholder true), so a
     * screen can show the placeholders apart. Empty parts are left out.
     *
     * @return list<array{text: string, placeholder: bool}>
     */
    public static function placeholderParts(string $text): array
    {
        $parts = [];
        foreach (preg_split(self::PLACEHOLDER, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $piece) {
            if ($i % 2 === 1) {
                $parts[] = ['text' => ':' . $piece, 'placeholder' => true];
            } elseif ($piece !== '') {
                $parts[] = ['text' => $piece, 'placeholder' => false];
            }
        }

        return $parts;
    }
}
