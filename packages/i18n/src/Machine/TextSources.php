<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** Registry of text sources by collection. Factories are lazy so registering costs nothing. */
final class TextSources
{
    /** @var array<string, \Closure(): TextSource> */
    private array $factories = [];

    /** @var array<string, \Closure(): bool> */
    private array $offered = [];

    /** @var array<string, bool> */
    private array $offeredAnswers = [];

    /** @var array<string, TextSource> */
    private array $built = [];

    /**
     * @param \Closure(): TextSource $factory
     * @param (\Closure(): bool)|null $offered asked once, when the collection is first looked up: false means
     *        it has nothing to translate and is not listed (null: always offered)
     */
    public function add(string $collection, \Closure $factory, ?\Closure $offered = null): void
    {
        $this->factories[$collection] = $factory;
        unset($this->built[$collection], $this->offeredAnswers[$collection]);
        if ($offered === null) {
            unset($this->offered[$collection]);
        } else {
            $this->offered[$collection] = $offered;
        }
    }

    public function has(string $collection): bool
    {
        if (!isset($this->factories[$collection])) {
            return false;
        }

        return !isset($this->offered[$collection]) || ($this->offeredAnswers[$collection] ??= ($this->offered[$collection])());
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
        return array_values(array_filter(array_keys($this->factories), $this->has(...)));
    }
}
