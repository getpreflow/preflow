<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

/**
 * An empty session that lives in memory for one render frame: what a role view or a render
 * admitted by a preview token gets in place of the editor's session (RenderSession). It starts
 * empty, keeps what the render sets for the rest of that render, and never reaches the real
 * session, its cookie or its storage. Its id is a random one of its own, never the editor's.
 *
 * @since 0.81.0
 */
final class IsolatedSession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $flash = [];

    /** @var array<string, mixed> */
    private array $previous = [];

    private bool $started = false;

    private string $id;

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
        $this->data = $this->flash = $this->previous = [];
        $this->regenerate();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $this->flash[$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->flash[$key] ?? $this->previous[$key] ?? $default;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function ageFlash(): void
    {
        $this->previous = $this->flash;
        $this->flash = [];
    }

    public function close(): void
    {
        $this->started = false;
    }
}
