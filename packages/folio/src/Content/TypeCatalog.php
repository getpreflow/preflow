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

    /** @return TypeListing[] */
    public function all(): array
    {
        return $this->listings ??= $this->scan();
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
            $key = $data['key'];
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
            $listings[] = new TypeListing(
                $key,
                $label,
                $frontend,
                isset($data['list']) && is_array($data['list']) ? $data['list'] : [],
                $singleton,
                is_bool($data['publishing'] ?? null) ? $data['publishing'] : ($frontend !== null || $singleton),
                $users,
                is_bool($data['transfer'] ?? null) ? $data['transfer'] : $users === null,
            );
        }

        return $listings;
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
