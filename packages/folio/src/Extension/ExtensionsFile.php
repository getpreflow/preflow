<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * config/folio/extensions.json, the committed list of enabled extensions:
 * {"enabled": {"<name>": {"disabled_parts": ["screen:x"]}}}. Unlisted extensions are off;
 * a missing file means everything is off.
 */
final class ExtensionsFile
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @return array<string, array{disabled_parts: list<string>}> by extension name */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ExtensionException("{$this->path} is not valid JSON: {$e->getMessage()}", 0, $e);
        }
        $enabled = is_array($data) ? ($data['enabled'] ?? []) : null;
        if (!is_array($enabled) || (array_is_list($enabled) && $enabled !== [])) {
            throw new ExtensionException("{$this->path}: \"enabled\" must be an object of extension names");
        }

        $entries = [];
        foreach ($enabled as $name => $entry) {
            $parts = is_array($entry) ? ($entry['disabled_parts'] ?? []) : null;
            if (!is_array($parts) || !array_is_list($parts) || array_filter($parts, 'is_string') !== $parts) {
                throw new ExtensionException("{$this->path}: \"enabled.{$name}.disabled_parts\" must be a list of part ids");
            }
            $entries[(string) $name] = ['disabled_parts' => $parts];
        }

        return $entries;
    }

    /** @param array<string, array{disabled_parts?: list<string>}> $enabled pretty-printed, keys and part ids sorted, trailing newline */
    public function write(array $enabled): void
    {
        ksort($enabled);
        $out = [];
        foreach ($enabled as $name => $entry) {
            $parts = array_values(array_unique($entry['disabled_parts'] ?? []));
            sort($parts);
            $out[$name] = ['disabled_parts' => $parts];
        }
        $json = json_encode(['enabled' => (object) $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        @mkdir(dirname($this->path), 0755, true);
        if (file_put_contents($this->path, $json . "\n", LOCK_EX) === false) {
            throw new ExtensionException("Could not write {$this->path}");
        }
    }
}
