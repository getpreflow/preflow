<?php

declare(strict_types=1);

namespace Preflow\Blocks\Preview;

/**
 * A type's "preview" option: the views a record of the type can be previewed in, each rendered by
 * a component the type names. One view (key "default", no label):
 *
 *     "preview": {"component": "Teaser"}
 *
 * or several, in declaration order, the first being the default:
 *
 *     "preview": {"views": {"card": {"label": "Card", "component": "TeaserCard"},
 *                           "wide": {"label": "Wide", "component": "TeaserWide"}}}
 *
 * A class-backed type declares the same shape as #[Entity(preview: [...])]. The shape is checked
 * here; whether each component exists is TypePreviews' boot check. "page" is no view key: it is
 * Folio's name for the preview of the type's route.
 *
 * @since 0.94.0
 */
final readonly class TypePreview
{
    /** The key of the one view the short form ({"component": …}) declares. */
    public const string DEFAULT_VIEW = 'default';
    /** Reserved: Folio's name for the preview of the type's route. */
    public const string RESERVED_VIEW = 'page';
    public const string VIEW_KEY_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    /**
     * @param array<string, PreviewView> $views by key, in declaration order (not empty)
     * @throws PreviewConfigException without views
     */
    public function __construct(
        public string $type,
        public array $views,
    ) {
        if ($views === []) {
            throw new PreviewConfigException(sprintf('Type "%s": a preview needs at least one view.', $type));
        }
    }

    /**
     * The type's preview from its schema (a model file's shape), null without "preview".
     *
     * @param array<string, mixed> $schema
     * @throws PreviewConfigException naming the type and what to change
     */
    public static function fromSchema(string $type, array $schema): ?self
    {
        if (!array_key_exists('preview', $schema)) {
            return null;
        }
        $config = $schema['preview'];
        $refuse = static fn (string $message, mixed ...$args): PreviewConfigException => new PreviewConfigException(sprintf('Type "%s": ', $type) . sprintf($message, ...$args));
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            throw $refuse('"preview" must be an object such as {"component": "Teaser"} or {"views": {"card": {"label": "Card", "component": "TeaserCard"}}}.');
        }
        foreach (array_keys($config) as $key) {
            if (!in_array($key, ['component', 'views'], true)) {
                throw $refuse('"preview" has the unknown key "%s"; it takes "component" (one view) or "views" (several).', $key);
            }
        }
        if (array_key_exists('component', $config) === array_key_exists('views', $config)) {
            throw $refuse('"preview" must set exactly one of "component" (one view, such as {"component": "Teaser"}) or "views" (several, such as {"views": {"card": {"label": "Card", "component": "TeaserCard"}}}).');
        }

        if (array_key_exists('component', $config)) {
            $component = $config['component'];
            if (!is_string($component) || $component === '') {
                throw $refuse('"preview.component" must be the name of a component, such as "Teaser".');
            }

            return new self($type, [self::DEFAULT_VIEW => new PreviewView(self::DEFAULT_VIEW, null, $component)]);
        }

        $declared = $config['views'];
        if (!is_array($declared) || $declared === [] || array_is_list($declared)) {
            throw $refuse('"preview.views" must be a non-empty object of views, such as {"card": {"label": "Card", "component": "TeaserCard"}}.');
        }
        $views = [];
        foreach ($declared as $key => $view) {
            $key = (string) $key;
            if (preg_match(self::VIEW_KEY_PATTERN, $key) !== 1) {
                throw $refuse('the preview view "%s" has a key that is not allowed; use lowercase letters, digits, "_" and "-", starting with a letter, such as "card".', $key);
            }
            if ($key === self::RESERVED_VIEW) {
                throw $refuse('the preview view "page" is reserved for the preview of the type\'s route; name the view differently, such as "full".');
            }
            if (!is_array($view) || ($view !== [] && array_is_list($view))) {
                throw $refuse('the preview view "%s" must be an object such as {"label": "Card", "component": "TeaserCard"}.', $key);
            }
            foreach (array_keys($view) as $option) {
                if (!in_array($option, ['component', 'label'], true)) {
                    throw $refuse('the preview view "%s" has the unknown key "%s"; a view takes "component" and "label".', $key, $option);
                }
            }
            $component = $view['component'] ?? null;
            if (!is_string($component) || $component === '') {
                throw $refuse('the preview view "%s" has no "component"; name the component that renders it, such as {"component": "TeaserCard"}.', $key);
            }
            if (array_key_exists('label', $view) && !is_string($view['label'])) {
                throw $refuse('the preview view "%s" has a "label" that is not text; give text such as "Card" (a translation key works too), or leave it out.', $key);
            }
            $views[$key] = new PreviewView($key, $view['label'] ?? null, $component);
        }

        return new self($type, $views);
    }

    /** The view by its key; an unknown key or null gives the first (the default). */
    public function view(?string $key): PreviewView
    {
        return ($key !== null ? ($this->views[$key] ?? null) : null) ?? $this->views[array_key_first($this->views)];
    }

    /**
     * The view keys in declaration order (the first is the default).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->views);
    }
}
