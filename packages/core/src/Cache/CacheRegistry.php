<?php

declare(strict_types=1);

namespace Preflow\Core\Cache;

/** Collects the CacheSources of an application; providers register theirs in register(). */
final class CacheRegistry
{
    /** @var array<string, CacheSource> */
    private array $sources = [];

    /** @var array<string, string> a key a source had before => its key now */
    private array $renamed = [];

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

    /** The source of $key, also under a key it had before (rename()). */
    public function get(string $key): ?CacheSource
    {
        return $this->sources[$key] ?? (isset($this->renamed[$key]) ? $this->sources[$this->renamed[$key]] ?? null : null);
    }

    /**
     * A source's key changed: get($old) answers with the source of $key (a deploy script that
     * clears the old key keeps working). all() and clearAll() list each source once, under its key.
     */
    public function rename(string $old, string $key): void
    {
        $this->renamed[$old] = $key;
    }

    /** The key $key is now, when it is an old key (rename()); null otherwise. */
    public function renamedTo(string $key): ?string
    {
        return isset($this->sources[$key]) ? null : $this->renamed[$key] ?? null;
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
