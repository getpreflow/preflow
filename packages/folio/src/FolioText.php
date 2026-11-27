<?php

declare(strict_types=1);

namespace Preflow\Folio;

use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Core\Context\Contexts;
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
    /** @param array<string, string|int> $params the key's :name parameters (an area's labelParams) */
    public static function label(string $text, array $params = []): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_]+)+$/', $text) !== 1) {
            return $text;
        }

        return self::get($text, $params);
    }

    /**
     * An area's or column's label in the interface language, with its parameters
     * ("block-library.columns.column" with ['n' => 2]: "Column 2").
     *
     * @since 0.92.0
     */
    public static function areaLabel(AreaDefinition $area): string
    {
        return self::label($area->label, $area->labelParams);
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
