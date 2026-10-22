<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

final class TypeCatalog
{
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
        return new TypeListing(
            $key,
            $label,
            $frontend,
            isset($data['list']) && is_array($data['list']) ? $data['list'] : [],
            $singleton,
            is_bool($data['publishing'] ?? null) ? $data['publishing'] : ($frontend !== null || $singleton),
            $users,
            is_bool($data['transfer'] ?? null) ? $data['transfer'] : $users === null,
            $section,
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
