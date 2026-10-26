<?php

declare(strict_types=1);

namespace Preflow\Folio;

use Preflow\I18n\Translator;

/**
 * Folio's words (the "folio" group) for code that renders admin text without a translator of
 * its own: field types, list helpers, the publish state, the delete plan. During an admin
 * request AdminLocaleMiddleware hands it the app's translator (in the user's interface
 * language, with the site's lang files and overrides); outside one (the console, unit tests)
 * the texts are Folio's English ones.
 */
final class FolioText
{
    private static ?Translator $translator = null;

    private static ?Translator $english = null;

    /** Sets the translator for the admin request being handled (null: none); returns the one before. */
    public static function use(?Translator $translator): ?Translator
    {
        $previous = self::$translator;
        self::$translator = $translator;

        return $previous;
    }

    /** @param array<string, string|int> $params */
    public static function get(string $key, array $params = []): string
    {
        return self::translator()->get($key, $params);
    }

    /** @param array<string, string|int> $params */
    public static function choice(string $key, int $count, array $params = []): string
    {
        return self::translator()->choice($key, $count, $params);
    }

    /**
     * A type's label as a noun inside a sentence ("No posts yet."): in lower case where the
     * language writes nouns so (folio.format.noun_case "lower"), unless it starts with an
     * acronym ("FAQ entries"); German keeps it as it is.
     */
    public static function noun(string $label): string
    {
        if (self::get('folio.format.noun_case') !== 'lower' || preg_match('/^\p{Lu}\p{Lu}/u', $label) === 1) {
            return $label;
        }

        return mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }

    /**
     * A text an extension gives in an attribute (#[ListColumn], #[FormPanel], #[RecordAction],
     * #[BulkAction] labels and confirm texts, a permission's label): when it has the shape of a
     * key ("members-admin.list.state") and the translator has a text for it, that text in the
     * interface language; otherwise the text as it is, so plain English labels stay as they are.
     */
    public static function label(string $text): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_]+)+$/', $text) !== 1) {
            return $text;
        }

        return self::get($text);
    }

    /** The interface language of the texts. */
    public static function locale(): string
    {
        return self::translator()->getLocale();
    }

    /** The admin request's translator, else Folio's own English texts. */
    public static function translator(): Translator
    {
        if (self::$translator !== null) {
            return self::$translator;
        }
        if (self::$english === null) {
            self::$english = new Translator(dirname(__DIR__) . '/lang/.none', 'en', 'en');
            self::$english->addDefaults('folio', dirname(__DIR__) . '/lang');
        }

        return self::$english;
    }
}
