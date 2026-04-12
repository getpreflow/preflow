<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Core\Http\Session\SessionInterface;

final class ArraySession implements SessionInterface
{
    private bool $started = false;
    private string $id;
    private array $data = [];
    private array $flashCurrent = [];
    private array $flashPrevious = [];

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function invalidate(): void
    {
        $this->id = bin2hex(random_bytes(16));
        $this->data = [];
        $this->flashCurrent = [];
        $this->flashPrevious = [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $this->flashCurrent[$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->flashPrevious[$key] ?? $this->flashCurrent[$key] ?? $default;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function ageFlash(): void
    {
        $this->flashPrevious = $this->flashCurrent;
        $this->flashCurrent = [];
    }

    public function close(): void
    {
        // No-op for in-memory session
    }
}
