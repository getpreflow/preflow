<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\TypeFamily;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Extension\ModelExtension;
use Preflow\Folio\Extension\Screen\ScreenRegistry;

final class TypeCatalog
{
    /**
     * Built-in field types whose values hold files: the ones implementing HandlesUpload or
     * ReferencesAssets (asset, an embed's poster and a link to a file, since 0.91), plus blocks
     * (hands uploads to nested types) and matrix (its assets()). The catalog is built before the
     * field types, so this list names them; a test keeps it equal to the built-in types that
     * implement those interfaces. A link holds files only when it allows its "asset" kind
     * (holdsFiles()). Field types a site registers itself (config/folio.php
     * "field_types") are not known here.
     */
    public const array FILE_HOLDING_FIELD_TYPES = ['asset', 'blocks', 'matrix', 'embed', 'link'];

    /**
     * @param \Preflow\Core\Boot\BootManifest|null $manifest the boot manifest the model files' listings come from when it has them
     * @param TypeRegistry|null                   $types    the types; a model file whose key a model class defines is that
     *                                                      class's admin file, listed with the class's schema
     * @param (\Closure(): ?\Preflow\Fields\EmbedProviders)|null $embedProviders the app's video hosts (0.91), asked when the types are read: an embed field listing an unregistered one is a boot error
     */
    public function __construct(
        private readonly string $modelsPath,
        private readonly ?\Preflow\Core\Boot\BootManifest $manifest = null,
        private readonly ?TypeRegistry $types = null,
        private readonly ?\Closure $embedProviders = null,
    ) {}

    /** @var TypeListing[]|null scanned once per catalog (one request) */
    private ?array $listings = null;

    /** @var TypeListing[] added at runtime */
    private array $added = [];

    /** @return TypeListing[] */
    public function all(): array
    {
        return $this->listings ??= array_merge($this->scan(), $this->added);
    }

    /**
     * Add a type defined at runtime (extension models, settings). Listed after the file types, in insertion order.
     * A "section" in the schema wins over $section.
     *
     * @param array<string, mixed> $schema same shape as a model JSON file
     * @param string               $group  the translation group of its labels (an extension's package group; "site" for the site's own)
     */
    public function add(array $schema, string $section = 'content', string $group = 'site'): void
    {
        $listing = $this->listing($schema, $section, $group);
        if ($this->has($listing->key)) {
            throw new \RuntimeException("Type \"{$listing->key}\" is already in the catalog");
        }
        $this->added[] = $listing;
        if ($this->listings !== null) {
            $this->listings[] = $listing;
        }
    }

    /** @return TypeListing[] */
    private function scan(): array
    {
        if ($this->manifest === null) {
            return $this->scanFiles();
        }

        return array_map(
            // The manifest holds a family as its value; one written before 0.75 has none.
            static fn (array $listing): TypeListing => new TypeListing(...['family' => is_string($listing['family'] ?? null) ? TypeFamily::tryFrom($listing['family']) : null] + $listing),
            $this->manifest->remember(
                'folio.type_listings',
                function (): array {
                    // The model classes are sources too: a changed class changes its listing.
                    $this->manifest?->addSources($this->classFiles());

                    return array_map(static fn (TypeListing $listing): array => ['family' => $listing->family?->value] + get_object_vars($listing), $this->scanFiles());
                },
                [$this->modelsPath],
            ),
        );
    }

    /** @return TypeListing[] the model files' types */
    private function scanFiles(): array
    {
        if (!is_dir($this->modelsPath)) {
            return [];
        }

        $listings = [];
        foreach (glob($this->modelsPath . '/*.json') ?: [] as $file) {
            $data = \Preflow\Core\Config\ParsedJsonFiles::read($file);
            if (!is_array($data) || !isset($data['key']) || !is_string($data['key'])) {
                continue; // skip malformed / incomplete definitions
            }
            if (ModelExtension::isPatch($data)) {
                continue; // adds to an extension's type, listed through add() once merged
            }
            // A model class's admin file: listed from the class's schema with the file laid over it,
            // so the checks below (hidden columns, file fields) see the class's fields.
            if ($this->types?->classFor($data['key']) !== null) {
                $data = $this->types->schema($data['key']);
            }
            $listings[] = $this->listing($data, 'content');
        }

        return $listings;
    }

    /** @return list<string> the files of the model classes that define types */
    private function classFiles(): array
    {
        $files = [];
        foreach ($this->types?->keys() ?? [] as $key) {
            $class = $this->types->classFor($key);
            $file = $class === null ? false : (new \ReflectionClass($class))->getFileName();
            if (is_string($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Whether a field's values can hold files. A link field holds one only when it allows the
     * "asset" kind (a web, email, phone or record-only link holds none).
     *
     * @param array<string, mixed> $field
     */
    private static function holdsFiles(array $field): bool
    {
        $type = $field['type'] ?? null;
        if ($type === 'link') {
            return \Preflow\Fields\LinkOptions::fromConfig($field)->allows(\Preflow\Fields\Values\LinkKind::Asset);
        }

        return in_array($type, self::FILE_HOLDING_FIELD_TYPES, true);
    }

    /** @param array<string, mixed> $data */
    private function listing(array $data, string $section, string $group = 'site'): TypeListing
    {
        $key = $data['key'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new FrontendConfigException('A type needs a "key"');
        }
        if (in_array($key, ScreenRegistry::RESERVED, true)) {
            throw new FrontendConfigException("Type \"{$key}\": the key is a path Folio uses itself; choose another");
        }
        $labels = self::text($data, 'label');
        $singular = self::text($data, 'label_singular');
        $label = \Preflow\I18n\Labels::pick($labels, 'en', 'en') ?? ucfirst($key);
        $frontend = isset($data['frontend']) && is_array($data['frontend']) ? $data['frontend'] : null;
        $singleton = ($data['singleton'] ?? false) === true;
        $users = null;
        if (array_key_exists('users', $data)) {
            if (!is_string($data['users']) || $data['users'] === '') {
                throw new FrontendConfigException("Type \"{$key}\": \"users\" must be a non-empty population name");
            }
            if ($frontend !== null) {
                throw new FrontendConfigException("Type \"{$key}\": a user type cannot have a \"frontend\"");
            }
            if ($singleton) {
                throw new FrontendConfigException("Type \"{$key}\": a user type cannot be a singleton");
            }
            $users = $data['users'];
        }
        if (array_key_exists('transfer', $data) && !is_bool($data['transfer'])) {
            throw new FrontendConfigException("Type \"{$key}\": \"transfer\" must be true or false");
        }
        $transfer = $data['transfer'] ?? $users === null;
        if (!$transfer) {
            foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field) {
                if (is_array($field) && self::holdsFiles($field)) {
                    throw new FrontendConfigException("Type \"{$key}\" keeps its records on the server, but its files would still be exported; file fields are not supported on it yet.");
                }
            }
        }
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        foreach ((array) ($data['list']['columns'] ?? []) as $column) {
            if (is_string($column) && is_array($fields[$column] ?? null) && ($fields[$column]['hidden'] ?? false) === true) {
                throw new FrontendConfigException("Type \"{$key}\": list column \"{$column}\" names a hidden field; remove it from \"list.columns\" or unhide the field");
            }
        }
        foreach ((array) ($data['list']['filters'] ?? []) as $filter) {
            if (is_string($filter) && is_array($fields[$filter] ?? null) && ($fields[$filter]['hidden'] ?? false) === true) {
                throw new FrontendConfigException("Type \"{$key}\": list filter \"{$filter}\" names a hidden field; remove it from \"list.filters\" or unhide the field");
            }
        }
        // A select taking its options from another field (0.89) has no fixed options to filter by or to set in bulk.
        foreach (['filters' => 'list filter "%s" is a select with "options_from", whose options differ per record; remove it from "list.filters"',
            'bulk' => '"list.bulk" names "%s", a select with "options_from", whose options differ per record; remove it from "list.bulk"'] as $listKey => $message) {
            foreach ((array) ($data['list'][$listKey] ?? []) as $name) {
                if (is_string($name) && is_array($fields[$name] ?? null) && ($fields[$name]['type'] ?? null) === 'select' && array_key_exists('options_from', $fields[$name])) {
                    throw new FrontendConfigException("Type \"{$key}\": " . sprintf($message, $name));
                }
            }
        }
        self::checkFieldLayout($key, $fields, $this->embedProviders !== null ? ($this->embedProviders)() : null);
        $section = $data['section'] ?? $section;
        if (!is_string($section) || preg_match('/^[a-z][a-z0-9_-]*$/', $section) !== 1) {
            throw new FrontendConfigException("Type \"{$key}\": \"section\" must be a lowercase name (a-z, 0-9, _ and -, starting with a letter)");
        }
        if (array_key_exists('order', $data) && !is_int($data['order'])) {
            throw new FrontendConfigException("Type \"{$key}\": \"order\" must be an integer");
        }
        if (array_key_exists('icon', $data) && (!is_string($data['icon']) || preg_match('/^ui-[a-z0-9-]+$/', $data['icon']) !== 1)) {
            throw new FrontendConfigException("Type \"{$key}\": \"icon\" must be a ui-* icon name");
        }
        if (array_key_exists('nav', $data) && !is_bool($data['nav'])) {
            throw new FrontendConfigException("Type \"{$key}\": \"nav\" must be true or false");
        }
        $family = self::family($key, $data, $frontend, $users);
        return new TypeListing(
            $key,
            $label,
            $frontend,
            isset($data['list']) && is_array($data['list']) ? $data['list'] : [],
            $singleton,
            is_bool($data['publishing'] ?? null) ? $data['publishing'] : ($frontend !== null || $singleton),
            $users,
            $transfer,
            $section,
            $data['order'] ?? 100,
            $data['icon'] ?? '',
            $data['nav'] ?? true,
            $labels,
            $singular,
            $group,
            $family,
        );
    }

    /** Where the editor puts a field: "main" (the form column, the default) or "side" (the side panel's Details card). @since 0.75 */
    public const array FIELD_PANELS = ['main', 'side'];

    /** How wide a field is in the form column: "full" (the default) or "half" (two half fields share a row). @since 0.75 */
    public const array FIELD_WIDTHS = ['full', 'half'];

    /**
     * The editor layout keys of the type's fields ("panel", "width"; since 0.75): a value outside
     * FIELD_PANELS / FIELD_WIDTHS is a boot error, so a typo never moves a field silently. So is
     * an invalid editor option of a built-in field type ("unit", "display", a tags field's limits
     * and options, a translatable tags field, a select's "options_from" since 0.89, a link, phone,
     * colour or code field's options, or "translatable" on any of the 0.91 field types).
     *
     * @param array<mixed> $fields
     */
    private static function checkFieldLayout(string $key, array $fields, ?\Preflow\Fields\EmbedProviders $embedProviders = null): void
    {
        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }
            foreach (['panel' => self::FIELD_PANELS, 'width' => self::FIELD_WIDTHS] as $option => $allowed) {
                if (array_key_exists($option, $field) && !in_array($field[$option], $allowed, true)) {
                    throw new FrontendConfigException("Type \"{$key}\": field \"{$name}\": \"{$option}\" must be \"" . implode('" or "', $allowed) . '"');
                }
            }
            if (array_key_exists('options_from', $field) && ($field['type'] ?? 'string') !== 'select') {
                throw new FrontendConfigException("Type \"{$key}\": field \"{$name}\": \"options_from\" is an option of a select field; make the field \"type\": \"select\" or remove it");
            }
            // The built-in field types' editor options (0.75): "unit" of a number, "display" of a
            // boolean or a select (with the "cards" options shape). Reported here, at boot.
            try {
                match ($field['type'] ?? 'string') {
                    'number', 'int', 'integer', 'float' => \Preflow\Folio\Field\Types\NumberFieldType::unit($field),
                    'boolean' => \Preflow\Folio\Field\Types\BooleanFieldType::display($field),
                    'select' => self::checkSelect($field, (string) $name, $fields),
                    'tags' => \Preflow\Folio\Field\Types\TagsFieldType::checkConfig($field),
                    default => null,
                };
            } catch (\LogicException $e) {
                throw new FrontendConfigException("Type \"{$key}\": field \"{$name}\": " . $e->getMessage(), 0, $e);
            }
        }
        // The options of the field types of preflow/fields (0.91), and a show_when on one of its
        // structured, datetime or time fields that is not "empty"/"filled": also for an extension
        // model defined after FieldsServiceProvider's boot check.
        try {
            \Preflow\Fields\FieldOptions::checkFields(array_filter($fields, 'is_array'), $embedProviders);
        } catch (\LogicException $e) {
            throw new FrontendConfigException("Type \"{$key}\": " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * A select's "options_from" against the type's fields (0.89), then its "display".
     *
     * @param array<string, mixed> $field
     * @param array<mixed>         $fields
     * @throws \LogicException
     */
    private static function checkSelect(array $field, string $name, array $fields): void
    {
        $types = [];
        foreach ($fields as $other => $definition) {
            $types[(string) $other] = is_array($definition) && is_string($definition['type'] ?? null) ? $definition['type'] : 'string';
        }
        \Preflow\Folio\Field\Types\SelectFieldType::checkOptionsFrom($field, $name, array_keys($types), $types);
        \Preflow\Folio\Field\Types\SelectFieldType::display($field);
    }

    /**
     * The type's "family": the declared one, else page for a type with a "frontend", member for a
     * user type, else none (a tint has to mean something the site said).
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $frontend
     */
    private static function family(string $key, array $data, ?array $frontend, ?string $users): ?TypeFamily
    {
        if (array_key_exists('family', $data)) {
            $family = is_string($data['family']) ? TypeFamily::tryFrom($data['family']) : null;
            if ($family === null) {
                $values = implode(', ', array_map(static fn (TypeFamily $f): string => $f->value, TypeFamily::cases()));
                throw new FrontendConfigException("Type \"{$key}\": \"family\" must be one of {$values}");
            }

            return $family;
        }

        return match (true) {
            $frontend !== null => TypeFamily::Page,
            $users !== null => TypeFamily::Member,
            default => null,
        };
    }

    /**
     * A display text of the type ("label", "label_singular"): a string, or a map of language
     * code => string (other entries dropped); null when absent or of another shape (ignored,
     * as a non-string label always was).
     *
     * @param array<string, mixed> $data
     * @return string|array<string, string>|null
     */
    private static function text(array $data, string $name): string|array|null
    {
        $value = $data[$name] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $map = array_filter($value, static fn ($text, $locale): bool => is_string($text) && is_string($locale), ARRAY_FILTER_USE_BOTH);

        return $map === [] ? null : $map;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function get(string $key): ?TypeListing
    {
        foreach ($this->all() as $listing) {
            if ($listing->key === $key) {
                return $listing;
            }
        }
        return null;
    }
}
