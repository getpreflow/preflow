<?php

declare(strict_types=1);

namespace Preflow\Ai\Settings;

/** {dir}/settings.json, replaced atomically (temp file + rename) so a reader never sees half a write. */
final class FileAiSettingsStore implements AiSettingsStore
{
    public function __construct(private readonly string $dir) {}

    public function get(string $section): array
    {
        $section = $this->all()[$section] ?? [];

        return is_array($section) ? $section : [];
    }

    public function put(string $section, array $data): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create the AI settings directory.');
        }
        $all = $this->all();
        $all[$section] = $data;

        $file = $this->file();
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($all, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write the AI settings.');
        }
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        $file = $this->file();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }

    private function file(): string
    {
        return rtrim($this->dir, '/') . '/settings.json';
    }
}
