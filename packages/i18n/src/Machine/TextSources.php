<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** Registry of text sources by collection. Factories are lazy so registering costs nothing. */
final class TextSources
{
    /** @var array<string, \Closure(): TextSource> */
    private array $factories = [];

    /** @var array<string, TextSource> */
    private array $built = [];

    /** @param \Closure(): TextSource $factory */
    public function add(string $collection, \Closure $factory): void
    {
        $this->factories[$collection] = $factory;
        unset($this->built[$collection]);
    }

    public function has(string $collection): bool
    {
        return isset($this->factories[$collection]);
    }

    public function get(string $collection): TextSource
    {
        if (!$this->has($collection)) {
            throw new \InvalidArgumentException("No text source for collection \"{$collection}\".");
        }

        return $this->built[$collection] ??= ($this->factories[$collection])();
    }

    /** @return list<string> */
    public function collections(): array
    {
        return array_keys($this->factories);
    }
}
