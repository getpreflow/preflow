<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

final class NativeSession implements SessionInterface
{
    private bool $started = false;

    /**
     * @param array{
     *   lifetime?: int,
     *   path?: string,
     *   domain?: string,
     *   secure?: bool,
     *   httponly?: bool,
     *   samesite?: string,
     * } $config
     */
    public function __construct(private readonly array $config = []) {}

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $options = [];

        if (isset($this->config['lifetime'])) {
            $options['cookie_lifetime'] = $this->config['lifetime'];
        }
        if (isset($this->config['path'])) {
            $options['cookie_path'] = $this->config['path'];
        }
        if (isset($this->config['domain'])) {
            $options['cookie_domain'] = $this->config['domain'];
        }
        if (isset($this->config['secure'])) {
            $options['cookie_secure'] = $this->config['secure'];
        }
        if (isset($this->config['httponly'])) {
            $options['cookie_httponly'] = $this->config['httponly'];
        }
        if (isset($this->config['samesite'])) {
            $options['cookie_samesite'] = $this->config['samesite'];
        }

        if (isset($this->config['cookie'])) {
            session_name($this->config['cookie']);
        }

        session_start($options);

        if (!isset($_SESSION['_flash'])) {
            $_SESSION['_flash'] = ['current' => [], 'previous' => []];
        }

        $this->started = true;
    }

    public function getId(): string
    {
        return session_id();
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        $_SESSION['_flash'] = ['current' => [], 'previous' => []];
        session_regenerate_id(true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash']['current'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash']['previous'][$key]
            ?? $_SESSION['_flash']['current'][$key]
            ?? $default;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function ageFlash(): void
    {
        $_SESSION['_flash']['previous'] = $_SESSION['_flash']['current'];
        $_SESSION['_flash']['current'] = [];
    }

    public function close(): void
    {
        if ($this->started && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
