<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

/**
 * A type's "layouts" option: the layouts its pages may use ("allowed", in picker order), the one a
 * new page starts with ("default", one of them), and pins: a page whose slug (the "slug_field",
 * default "slug", in the default locale) is a key of "pinned" always uses that layout, which
 * "allowed" need not list.
 *
 *     "layouts": {"allowed": ["landing", "standard"], "default": "standard", "pinned": {"home": "home"}}
 *
 * An allowed or pinned key no class declares is no error here (a deleted class must not stop the
 * boot): such a page resolves to the default (PageLayouts).
 *
 * @since 0.92.0
 */
final readonly class TypeLayouts
{
    public const string DEFAULT_SLUG_FIELD = 'slug';

    /**
     * @param list<string>          $allowed
     * @param array<string, string> $pinned page slug => layout key
     */
    public function __construct(
        public string $type,
        public array $allowed,
        public string $default,
        public array $pinned = [],
        public string $slugField = self::DEFAULT_SLUG_FIELD,
    ) {}

    /**
     * The type's layouts from its schema (a model file's shape), null without "layouts".
     *
     * @param array<string, mixed> $schema
     * @throws LayoutConfigException naming the type and what to change
     */
    public static function fromSchema(string $type, array $schema): ?self
    {
        if (!array_key_exists('layouts', $schema)) {
            return null;
        }
        $config = $schema['layouts'];
        $refuse = static fn (string $message): LayoutConfigException => new LayoutConfigException(sprintf('Type "%s": %s', $type, $message));
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            throw $refuse('"layouts" must be an object such as {"allowed": ["landing", "standard"], "default": "standard"}.');
        }

        $allowed = $config['allowed'] ?? null;
        if (!is_array($allowed) || $allowed === [] || !array_is_list($allowed) || array_filter($allowed, static fn (mixed $key): bool => !is_string($key) || $key === '') !== []) {
            throw $refuse('"layouts.allowed" must be a non-empty list of layout keys, such as ["landing", "standard"].');
        }
        $list = implode(', ', $allowed);

        $default = $config['default'] ?? null;
        if (!is_string($default) || $default === '') {
            throw $refuse(sprintf('"layouts.default" is missing; name the layout a new page starts with, one of "layouts.allowed" (%s).', $list));
        }
        if (!in_array($default, $allowed, true)) {
            throw $refuse(sprintf('"layouts.default" is "%s", which "layouts.allowed" does not list (%s); choose one of them, or add it to "layouts.allowed".', $default, $list));
        }

        $pinned = $config['pinned'] ?? [];
        if (!is_array($pinned) || ($pinned !== [] && array_is_list($pinned))) {
            throw $refuse('"layouts.pinned" must map page slugs to layout keys, such as {"home": "home"}.');
        }
        $pins = [];
        foreach ($pinned as $slug => $layout) {
            if ((string) $slug === '' || !is_string($layout) || $layout === '') {
                throw $refuse('"layouts.pinned" must map page slugs to layout keys, such as {"home": "home"}.');
            }
            $pins[(string) $slug] = $layout;
        }

        $slugField = $config['slug_field'] ?? self::DEFAULT_SLUG_FIELD;
        if (!is_string($slugField) || $slugField === '') {
            throw $refuse('"layouts.slug_field" must be the name of the field that holds the page\'s slug, such as "slug".');
        }
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        if ($pins !== [] && !array_key_exists($slugField, $fields)) {
            throw $refuse(sprintf('"layouts.pinned" pins pages by the field "%s" ("layouts.slug_field"), which the type does not have; add that field, or set "layouts.slug_field" to the field that holds the page\'s slug.', $slugField));
        }

        return new self($type, array_values($allowed), $default, $pins, $slugField);
    }

    /** The layout a page with this slug (its default-locale value) is pinned to; null when none. */
    public function pinFor(?string $slug): ?string
    {
        return $slug === null || $slug === '' ? null : ($this->pinned[$slug] ?? null);
    }
}
