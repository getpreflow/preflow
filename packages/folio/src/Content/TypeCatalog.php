<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

final class TypeCatalog
{
    public function __construct(
        private readonly string $modelsPath,
    ) {}

    /** @return TypeListing[] */
    public function all(): array
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
            $listings[] = new TypeListing(
                $key,
                $label,
                isset($data['frontend']) && is_array($data['frontend']) ? $data['frontend'] : null,
                isset($data['list']) && is_array($data['list']) ? $data['list'] : [],
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
