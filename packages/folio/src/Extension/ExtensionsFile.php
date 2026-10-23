<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * config/folio/extensions.json, the committed list of enabled extensions:
 * {"enabled": {"<name>": {"disabled_parts": ["screen:x"], "enabled_parts": ["model:y"]}}}.
 * Unlisted extensions are off; a missing file means everything is off. "enabled_parts" (written
 * only when not empty) switches on opt-in models, which are off with their extension otherwise.
 */
final class ExtensionsFile
{
    /** @param string|null $modelsPath the site's models directory: with it, disable() refuses what a site model file extends */
    public function __construct(
        private readonly string $path,
        private readonly ?string $modelsPath = null,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @return array<string, array{disabled_parts: list<string>, enabled_parts?: non-empty-list<string>}> by extension name */
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
            $opted = is_array($entry) ? ($entry['enabled_parts'] ?? []) : [];
            if (!is_array($opted) || !array_is_list($opted) || array_filter($opted, 'is_string') !== $opted) {
                throw new ExtensionException("{$this->path}: \"enabled.{$name}.enabled_parts\" must be a list of part ids");
            }
            $entries[(string) $name] = ['disabled_parts' => $parts] + ($opted !== [] ? ['enabled_parts' => $opted] : []);
        }

        return $entries;
    }

    /** Enables an extension (kept as is when it already is), or with $part one part of an enabled extension. */
    public function enable(ExtensionDefinition $extension, ?string $part = null): void
    {
        $this->change($extension, $part, true);
    }

    /** Disables an extension (its data stays), or with $part one part of an enabled extension. */
    public function disable(ExtensionDefinition $extension, ?string $part = null): void
    {
        if ($this->modelsPath !== null) {
            ModelExtension::assertCanDisable($extension, $part, $this->modelsPath);
        }
        $this->change($extension, $part, false);
    }

    /** Removes an extension's entry by name, whether or not it is still discovered; false when it had none. */
    public function remove(string $name): bool
    {
        $entries = $this->read();
        if (!isset($entries[$name])) {
            return false;
        }
        unset($entries[$name]);
        $this->write($entries);

        return true;
    }

    private function change(ExtensionDefinition $extension, ?string $part, bool $on): void
    {
        $entries = $this->read();
        $name = $extension->name;
        if ($part === null) {
            if ($on) {
                $entries[$name] ??= ['disabled_parts' => []];
            } else {
                unset($entries[$name]);
            }
            $this->write($entries);

            return;
        }
        if (!$extension->hasPart($part)) {
            throw new ExtensionException("Extension \"{$name}\" has no part \"{$part}\".");
        }
        if (!isset($entries[$name])) {
            throw new ExtensionException("Extension \"{$name}\" is not enabled; enable it before its parts.");
        }
        $disabled = array_values(array_diff($entries[$name]['disabled_parts'], [$part]));
        $optIn = false;
        foreach ($extension->parts as $declared) {
            $optIn = $optIn || ($declared['id'] === $part && ($declared['opt_in'] ?? false) === true);
        }
        if ($optIn) { // off unless listed: switched on and off through enabled_parts
            $opted = array_values(array_diff($entries[$name]['enabled_parts'] ?? [], [$part]));
            $entries[$name]['disabled_parts'] = $disabled;
            $entries[$name]['enabled_parts'] = $on ? [...$opted, $part] : $opted;
        } else {
            $entries[$name]['disabled_parts'] = $on ? $disabled : [...$disabled, $part];
        }
        $this->write($entries);
    }

    /** @param array<string, array{disabled_parts?: list<string>, enabled_parts?: list<string>}> $enabled pretty-printed, keys and part ids sorted, trailing newline */
    public function write(array $enabled): void
    {
        ksort($enabled);
        $out = [];
        foreach ($enabled as $name => $entry) {
            $parts = array_values(array_unique($entry['disabled_parts'] ?? []));
            sort($parts);
            $out[$name] = ['disabled_parts' => $parts];
            $opted = array_values(array_unique($entry['enabled_parts'] ?? []));
            if ($opted !== []) {
                sort($opted);
                $out[$name]['enabled_parts'] = $opted;
            }
        }
        $json = json_encode(['enabled' => (object) $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        @mkdir(dirname($this->path), 0755, true);
        if (file_put_contents($this->path, $json . "\n", LOCK_EX) === false) {
            throw new ExtensionException("Could not write {$this->path}");
        }
    }
}
