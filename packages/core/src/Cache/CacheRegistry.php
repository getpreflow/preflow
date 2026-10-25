<?php

declare(strict_types=1);

namespace Preflow\Core\Cache;

/** Collects the CacheSources of an application; providers register theirs in register(). */
final class CacheRegistry
{
    /** @var array<string, CacheSource> */
    private array $sources = [];

    public function register(CacheSource $source): void
    {
        if (isset($this->sources[$source->key()])) {
            throw new \LogicException("Cache source [{$source->key()}] is already registered.");
        }

        $this->sources[$source->key()] = $source;
    }

    /** @return list<CacheSource> */
    public function all(): array
    {
        return array_values($this->sources);
    }

    public function get(string $key): ?CacheSource
    {
        return $this->sources[$key] ?? null;
    }

    /** @return array<string, CacheCleared> */
    public function clearAll(): array
    {
        $cleared = [];
        foreach ($this->sources as $key => $source) {
            $cleared[$key] = $source->clear();
        }

        return $cleared;
    }
}
