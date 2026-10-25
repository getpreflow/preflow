<?php

declare(strict_types=1);

namespace Preflow\Core\Cache;

/** Reads and resets PHP's OPcache of the current SAPI (the CLI has its own, usually disabled). */
class OpcacheStatus
{
    public function enabled(): bool
    {
        return is_array($this->status());
    }

    /** @return array{memory_used: int, memory_free: int, scripts: int, hit_rate: float}|null */
    public function stats(): ?array
    {
        $status = $this->status();
        if (!is_array($status)) {
            return null;
        }

        return [
            'memory_used' => (int) ($status['memory_usage']['used_memory'] ?? 0),
            'memory_free' => (int) ($status['memory_usage']['free_memory'] ?? 0),
            'scripts' => (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0),
            'hit_rate' => round((float) ($status['opcache_statistics']['opcache_hit_rate'] ?? 0), 1),
        ];
    }

    public function reset(): bool
    {
        return function_exists('opcache_reset') && @opcache_reset();
    }

    private function status(): array|false
    {
        return function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    }
}
