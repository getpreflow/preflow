<?php

declare(strict_types=1);

namespace Preflow\Htmx;

final class ResponseHeaders
{
    /** @var array<string, string> */
    private array $headers = [];

    public function set(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function get(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->headers;
    }

    public function clear(): void
    {
        $this->headers = [];
    }
}
