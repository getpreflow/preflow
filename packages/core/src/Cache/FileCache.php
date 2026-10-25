<?php

declare(strict_types=1);

namespace Preflow\Core\Cache;

/** A single cache file, such as a compiled route table. */
final class FileCache implements CacheSource
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $file,
        private readonly ?string $note = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function stats(): CacheStats
    {
        if (!is_file($this->file)) {
            return new CacheStats(0, 0, $this->note);
        }

        return new CacheStats(1, (int) filesize($this->file), $this->note);
    }

    public function clear(): CacheCleared
    {
        if (!is_file($this->file)) {
            return new CacheCleared(0, 0);
        }

        $bytes = (int) filesize($this->file);

        if (!@unlink($this->file)) {
            return new CacheCleared(0, 0);
        }

        if (str_ends_with($this->file, '.php') && function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->file, true);
        }

        return new CacheCleared(1, $bytes);
    }
}
