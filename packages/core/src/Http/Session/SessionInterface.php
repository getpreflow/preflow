<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

interface SessionInterface
{
    public function start(): void;

    public function getId(): string;

    public function regenerate(): void;

    public function invalidate(): void;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    public function flash(string $key, mixed $value): void;

    public function getFlash(string $key, mixed $default = null): mixed;

    public function isStarted(): bool;

    public function ageFlash(): void;

    public function close(): void;
}
