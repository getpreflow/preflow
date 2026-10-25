<?php

declare(strict_types=1);

namespace Preflow\Core\Cache;

/** A cache the framework or the app owns, listed and cleared through the CacheRegistry. */
interface CacheSource
{
    public function key(): string;

    public function label(): string;

    public function stats(): CacheStats;

    public function clear(): CacheCleared;
}
