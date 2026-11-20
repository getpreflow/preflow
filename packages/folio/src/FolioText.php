<?php

declare(strict_types=1);

namespace Preflow\Folio;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\RenderContext;
use Preflow\I18n\Translator;

/**
 * Folio's words (the "folio" group) for code that renders admin text without a translator of
 * its own: field types, list helpers, the publish state, the delete plan. On the admin surface
 * (Contexts::current()->surface, which AdminLocaleMiddleware enters for an admin request) the
 * texts come from the app's translator in the container, in the user's interface language with
 * the site's lang files and overrides. Elsewhere they are Folio's own texts (its lang files, no
 * site overrides): on the public surface in the render's locale (the translator follows the
 * context since 0.81), outside every render (the console, unit tests) in English.
 */
final class FolioText
{
    /** @var (\Closure(): ?Translator)|null the app's translator, from its container (FolioServiceProvider) */
    private static ?\Closure $source = null;

    private static ?Translator $english = null;

    /**
     * Where the app's translator comes from (null: nowhere, the English texts only). Set once at
     * boot by FolioServiceProvider; it holds no request state: the translator follows the render context.
     *
     * @param (\Closure(): ?Translator)|null $source
     * @internal FolioServiceProvider, and tests that render admin texts without an app
     * @since 0.81.0
     */
    public static function translatorFrom(?\Closure $source): void
    {
        self::$source = $source;
    }

    /**
     * Enters the admin surface in $translator's locale for the rest of the current render (null:
     * the public surface), and returns the translator FolioText gave before. The texts then come
     * from the app's translator (translatorFrom()), not from $translator. Outside every render it
     * changes nothing (the texts there are the English ones).
     *
     * @deprecated 0.81, removed in 0.83: Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale)), or
     *             Contexts::within($ctx->admin($uiLocale), $fn) for a stretch of the render
     */
    public static function use(?Translator $translator): ?Translator
    {
        Deprecations::once('Preflow\Folio\FolioText::use() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale)).');
        $previous = self::translator();
        if (Contexts::depth() > 0) {
            $locale = $translator?->getLocale();
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $locale === null
                ? $ctx->public()
                : $ctx->admin($locale, $ctx->surface->admin ? $ctx->surface->overrides : true));
        }

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

    /** The app's translator on the admin surface, else Folio's own texts (English outside every render). */
    public static function translator(): Translator
    {
        if (Contexts::peek()?->surface->admin === true && self::$source !== null) {
            $translator = (self::$source)();
            if ($translator !== null) {
                return $translator;
            }
        }
        if (self::$english === null) {
            self::$english = new Translator(dirname(__DIR__) . '/lang/.none', 'en', 'en');
            self::$english->addDefaults('folio', dirname(__DIR__) . '/lang');
            self::$english->addDefaults('rbac-admin', dirname(__DIR__) . '/lang'); // the rbac admin's field types
            // preflow/ai's texts (the AI limits' messages), when it is installed
            if (\Preflow\I18n\Machine\AiPackage::installed()) {
                self::$english->addDefaults('ai', \Preflow\Ai\Ai::langPath());
            }
        }

        return self::$english;
    }
}
