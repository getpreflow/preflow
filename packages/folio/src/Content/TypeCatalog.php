<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Folio\Extension\ModelExtension;
use Preflow\Folio\Extension\Screen\ScreenRegistry;

final class TypeCatalog
{
    /**
     * Built-in field types whose values hold files: the ones implementing HandlesUpload, plus
     * blocks (hands uploads to nested types) and matrix (its assets()). Field types a site
     * registers itself (config/folio.php "field_types") are not known here.
     */
    public const array FILE_HOLDING_FIELD_TYPES = ['asset', 'blocks', 'matrix'];

    public function __construct(
        private readonly string $modelsPath,
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
     */
    public function add(array $schema, string $section = 'content'): void
    {
        $listing = $this->listing($schema, $section);
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
        if (!is_dir($this->modelsPath)) {
            return [];
        }

        $listings = [];
        foreach (glob($this->modelsPath . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !isset($data['key']) || !is_string($data['key'])) {
                continue; // skip malformed / incomplete definitions
            }
            if (ModelExtension::isPatch($data)) {
                continue; // adds to an extension's type, listed through add() once merged
            }
            $listings[] = $this->listing($data, 'content');
        }

        return $listings;
    }

    /** @param array<string, mixed> $data */
    private function listing(array $data, string $section): TypeListing
    {
        $key = $data['key'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new FrontendConfigException('A type needs a "key"');
        }
        if (in_array($key, ScreenRegistry::RESERVED, true)) {
            throw new FrontendConfigException("Type \"{$key}\": the key is a path Folio uses itself; choose another");
        }
        $label = (isset($data['label']) && is_string($data['label']))
            ? $data['label']
            : ucfirst($key);
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
                if (is_array($field) && in_array($field['type'] ?? null, self::FILE_HOLDING_FIELD_TYPES, true)) {
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
        );
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
