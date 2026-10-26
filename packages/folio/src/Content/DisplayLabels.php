<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\ModelLabelKeys;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\FolioText;
use Preflow\I18n\Labels;
use Preflow\I18n\Translator;

/**
 * The display texts of model files (type labels, sidebar sections, field labels, help texts,
 * select options) in the admin's interface language. For each: the key in the type's
 * translation group (ModelLabelKeys: "site" for the site's own types, the package's group
 * for a type an extension ships; an admin override, the site's lang file, the package's
 * file), then the model file's map (the user's language, the site default, English), then
 * its plain string, then a humanized name. Outside an admin request FolioText's English.
 */
final class DisplayLabels
{
    /** Folio's verbs of a type's standard permissions ("{type}.view" …), the declared English label of each. */
    private const array VERBS = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'publish' => 'Publish', 'delete' => 'Delete', 'upload' => 'Upload'];

    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly ?TypeRegistry $types = null,
        private readonly ?\Preflow\Folio\Extension\ExtensionRegistry $extensions = null,
    ) {}

    /** The translation group of a type's labels. */
    public function group(string $type): string
    {
        return $this->catalog->get($type)?->group ?? 'site';
    }

    /** The type's label ("Apps"); the key, else ucfirst. */
    public function type(string $type): string
    {
        $listing = $this->catalog->get($type);

        return Labels::resolve(self::translator(), ModelLabelKeys::type($this->group($type), $type), $listing?->labels) ?? ucfirst($type);
    }

    /** The type's label for one record ("App"); null when neither a key nor the file gives one. */
    public function singular(string $type): ?string
    {
        return Labels::resolve(self::translator(), ModelLabelKeys::singular($this->group($type), $type), $this->catalog->get($type)?->singular);
    }

    /**
     * The list's empty state ("No members yet."): the type's own sentence {group}.types.{type}.empty
     * when its group has one (a label that doesn't fit Folio's sentence, e.g. German "Team"),
     * else Folio's sentence around the label.
     */
    public function emptyState(string $type): string
    {
        $own = Labels::resolve(self::translator(), $this->group($type) . '.types.' . $type . '.empty', null);

        return $own ?? FolioText::get('folio.list.empty', ['label' => FolioText::noun($this->type($type))]);
    }

    /**
     * A permission's label on the roles screen. In order: a declared label that is a key
     * (FolioText::label()); Folio's own permissions (folio.permissions.names.{name}); an
     * extension's permission ("{extension}.{rest}") as {its group}.permissions.{rest}; a
     * standard verb ("View") as folio.permissions.verbs.{verb}; else the declared label.
     */
    public function permission(string $name, string $declared): string
    {
        $translator = self::translator();
        $label = FolioText::label($declared);
        if ($label !== $declared) {
            return $label;
        }
        $own = Labels::resolve($translator, 'folio.permissions.names.' . $name, null);
        if ($own !== null) {
            return $own;
        }
        [$prefix, $rest] = array_pad(explode('.', $name, 2), 2, '');
        $group = $this->extensionGroup($prefix);
        if ($group !== null && $rest !== '') {
            $text = Labels::resolve($translator, $group . '.permissions.' . $rest, null);
            if ($text !== null) {
                return $text;
            }
        }
        $verb = substr($name, (int) strrpos($name, '.') + 1);
        if ((self::VERBS[$verb] ?? null) === $declared) {
            return FolioText::get('folio.permissions.verbs.' . $verb);
        }

        return $declared;
    }

    /**
     * The heading of a group of permissions on the roles screen, $permission being one of the
     * group's: Folio's groups (folio.permissions.groups.*), a content type's label, an
     * extension's {group}.extension.label; else the group as declared.
     */
    public function permissionGroup(string $group, string $permission): string
    {
        $prefix = explode('.', $permission, 2)[0];
        if (($group === 'Folio' && in_array($prefix, ['folio', 'translations'], true)) || ($group === 'Assets' && $prefix === 'assets')) {
            return FolioText::get('folio.permissions.groups.' . strtolower($group));
        }
        $listing = $this->catalog->get($prefix);
        if ($listing !== null && $listing->label === $group) {
            return $this->type($prefix);
        }
        $extension = $this->extensionGroup($prefix);
        if ($extension !== null && $this->extensions?->get($prefix)->label === $group) {
            return Labels::resolve(self::translator(), $extension . '.extension.label', null) ?? $group;
        }

        return $group;
    }

    /** The translation group of the extension named $name, when it has one of its own. */
    private function extensionGroup(string $name): ?string
    {
        if ($this->extensions === null) {
            return null;
        }
        try {
            $group = $this->extensions->get($name)->labelGroup();
        } catch (\Preflow\Folio\Extension\ExtensionException) {
            return null;
        }

        return $group === 'site' ? null : $group;
    }

    /** A field's label; the humanized field name when nothing gives one. */
    public function field(string $type, string $field): string
    {
        return $this->fieldText($type, $field) ?? (new \Preflow\Form\FieldRenderer())->humanize($field);
    }

    /** A field's label from a key, the file's map or its string; null (the renderer's own fallback) otherwise. */
    public function fieldText(string $type, string $field): ?string
    {
        $def = $this->definition($type, $field);

        return Labels::resolve(self::translator(), ModelLabelKeys::field($this->group($type), $type, $field), $def === null ? null : ($def->labels ?: $def->label));
    }

    public function help(string $type, string $field): ?string
    {
        $def = $this->definition($type, $field);

        return Labels::resolve(self::translator(), ModelLabelKeys::help($this->group($type), $type, $field), $def === null ? null : ($def->helps ?: $def->help));
    }

    /** One select option's label; the value itself when nothing gives one. */
    public function option(string $type, string $field, string $value): string
    {
        return $this->options($type, $field)[$value] ?? $value;
    }

    /**
     * A select field's options as value => label. "options" is a map (value => text or a map
     * per language) or a list whose values are the labels; $config: other options than the
     * field's own (default: the definition's config).
     *
     * @param array<string, mixed>|null $config
     * @return array<string, string>
     */
    public function options(string $type, string $field, ?array $config = null): array
    {
        $config ??= $this->definition($type, $field)?->config ?? [];
        $raw = is_array($config['options'] ?? null) ? $config['options'] : [];
        $group = $this->group($type);
        $translator = self::translator();
        $out = [];
        foreach ($raw as $key => $label) {
            $value = array_is_list($raw) ? (is_scalar($label) ? (string) $label : '') : (string) $key;
            $text = is_string($label) || is_array($label) ? $label : (is_scalar($label) ? (string) $label : null);
            $optionKey = ModelLabelKeys::option($group, $type, $field, $value);
            $out[$value] = ($optionKey !== null ? Labels::resolve($translator, $optionKey, $text) : self::pick($text)) ?? $value;
        }

        return $out;
    }

    /**
     * The heading of a sidebar section a type names: the key {group}.sections.{name} of a
     * type in it, else of the site; else the humanized name ("member_area": "Member area").
     */
    public function section(string $section): string
    {
        $translator = self::translator();
        $groups = [];
        foreach ($this->catalog->all() as $listing) {
            if ($listing->section === $section) {
                $groups[$listing->group] = true;
            }
        }
        foreach ([...array_keys($groups), 'site'] as $group) {
            $text = Labels::resolve($translator, ModelLabelKeys::section((string) $group, $section), null);
            if ($text !== null) {
                return $text;
            }
        }

        return ucfirst(str_replace(['_', '-'], ' ', $section));
    }

    /**
     * A text that is a string or a map per language, picked for the interface language (then
     * the site default, then English); for texts without a key (asset library fields).
     *
     * @param string|array<string, string>|null $value
     */
    public static function pick(string|array|null $value): ?string
    {
        $translator = self::translator();

        return Labels::pick($value, $translator->getLocale(), $translator->getFallbackLocale());
    }

    private function definition(string $type, string $field): ?\Preflow\Data\TypeFieldDefinition
    {
        if ($this->types === null || !$this->types->has($type)) {
            return null;
        }

        return $this->types->get($type)->fields[$field] ?? null;
    }

    private static function translator(): Translator
    {
        return FolioText::translator();
    }
}
