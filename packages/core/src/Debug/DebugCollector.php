<?php

declare(strict_types=1);

namespace Preflow\Core\Debug;

final class DebugCollector
{
    private float $startTime;
    private array $queries = [];
    private array $components = [];
    private ?array $route = null;
    private array $assets = ['css_count' => 0, 'js_count' => 0, 'css_bytes' => 0, 'js_bytes' => 0];

    public function __construct()
    {
        $this->startTime = hrtime(true);
    }

    public function logQuery(string $sql, array $bindings, float $durationMs, string $driver = 'default'): void
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings, 'duration_ms' => $durationMs, 'driver' => $driver];
    }

    public function logComponent(string $class, string $id, float $durationMs, array $props = []): void
    {
        $this->components[] = ['class' => $class, 'id' => $id, 'duration_ms' => $durationMs, 'props' => $props];
    }

    public function setRoute(string $mode, string $handler, array $parameters = []): void
    {
        $this->route = ['mode' => $mode, 'handler' => $handler, 'parameters' => $parameters];
    }

    public function setAssets(int $cssCount, int $jsCount, int $cssBytes, int $jsBytes): void
    {
        $this->assets = ['css_count' => $cssCount, 'js_count' => $jsCount, 'css_bytes' => $cssBytes, 'js_bytes' => $jsBytes];
    }

    public function getQueries(): array { return $this->queries; }
    public function getComponents(): array { return $this->components; }
    public function getRoute(): ?array { return $this->route; }
    public function getAssets(): array { return $this->assets; }

    public function getTotalTime(): float
    {
        return (hrtime(true) - $this->startTime) / 1_000_000;
    }

    public function getQueryTime(): float
    {
        return array_sum(array_column($this->queries, 'duration_ms'));
    }
}
