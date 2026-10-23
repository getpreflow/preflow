<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

use Preflow\Folio\Content\FrontendConfigException;

/**
 * A site model file with "extends": true is a patch on a type an enabled extension ships (its
 * models and its "{name}_settings" singleton): the site adds fields (e.g. company fields on
 * "member") without forking the extension. The patch is never a type of its own; the registries
 * skip it and the provider merges it here. Only fields and list columns change: what decides
 * where and how records are stored stays the extension's.
 */
final class ModelExtension
{
    /** Options a patch may repeat but not change. */
    private const array LOCKED = ['storage', 'table', 'users', 'id_field', 'key'];

    /** What a patch adds or replaces (list: only its "columns"). */
    private const array PATCHABLE = ['extends', 'fields', 'list'];

    /** Whether a decoded model file is a patch rather than a type. */
    public static function isPatch(mixed $schema): bool
    {
        return is_array($schema) && ($schema['extends'] ?? false) === true;
    }

    /**
     * The patch files in the models directory, by key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function patches(string $modelsPath): array
    {
        return array_map(static fn (array $patch): array => $patch['schema'], self::scan($modelsPath));
    }

    /**
     * The paths of the patch files in the models directory, by key.
     *
     * @return array<string, string>
     */
    public static function files(string $modelsPath): array
    {
        return array_map(static fn (array $patch): string => $patch['file'], self::scan($modelsPath));
    }

    /**
     * The type keys an extension (or one part of it) ships: its models and its settings singleton.
     *
     * @return list<string>
     */
    public static function typeKeys(ExtensionDefinition $extension, ?string $part = null): array
    {
        $keys = [];
        foreach ($extension->parts as $candidate) {
            if ($part !== null && $candidate['id'] !== $part) {
                continue;
            }
            $key = match ($candidate['kind']) {
                'model' => substr($candidate['id'], 6),
                'settings' => Settings\ExtensionSettings::typeKey($extension->name),
                default => null,
            };
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Refuses to switch off an extension (or part) whose types the site extends: the patch
     * would then extend nothing and every request, the admin's too, would fail at boot.
     */
    public static function assertCanDisable(ExtensionDefinition $extension, ?string $part, string $modelsPath): void
    {
        $files = self::files($modelsPath);
        $blocking = [];
        foreach (self::typeKeys($extension, $part) as $key) {
            if (isset($files[$key])) {
                $blocking[] = "the site model file {$files[$key]} extends its type \"{$key}\"";
            }
        }
        if ($blocking !== []) {
            $what = $part === null ? "extension \"{$extension->name}\"" : "part \"{$part}\" of extension \"{$extension->name}\"";
            throw new ExtensionException("Can't disable {$what}: " . implode('; ', $blocking) . '. Remove the file (or its "extends": true) first.');
        }
    }

    /**
     * The boot error for a patch no enabled extension type takes: names the file and, when a
     * switched-off extension (or part) ships the type, the command that switches it back on.
     */
    public static function orphanError(string $key, string $modelsPath, ExtensionRegistry $registry): FrontendConfigException
    {
        $file = self::files($modelsPath)[$key] ?? "{$modelsPath}/{$key}.json";
        $message = "Model \"{$key}\" extends an extension type, but no enabled extension defines it ({$file}).";
        foreach ($registry->all() as $extension) {
            foreach ($extension->parts as $part) {
                if (self::typeKeys($extension, $part['id']) !== [$key]) {
                    continue;
                }
                $command = "preflow folio:extensions:enable {$extension->name}" . ($registry->isEnabled($extension->name) ? " --part={$part['id']}" : '');

                return new FrontendConfigException("{$message} Extension \"{$extension->name}\" ships it but is switched off; run {$command}, or remove the file.");
            }
        }

        return new FrontendConfigException("{$message} Enable the extension that ships it, or remove the file.");
    }

    /** @return array<string, array{file: string, schema: array<string, mixed>}> */
    private static function scan(string $modelsPath): array
    {
        $patches = [];
        foreach (is_dir($modelsPath) ? (glob($modelsPath . '/*.json') ?: []) : [] as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            if (self::isPatch($schema)) {
                $key = is_string($schema['key'] ?? null) && $schema['key'] !== '' ? $schema['key'] : basename($file, '.json');
                $patches[$key] = ['file' => $file, 'schema' => $schema];
            }
        }

        return $patches;
    }

    /**
     * The extension's schema with the site's fields appended (or each placed "after" a field it names) and its list columns, when given, in place of the extension's.
     * A patch holds only key, extends, fields and list.columns, and may repeat the locked options
     * unchanged; anything else is an error, so a key the merge would drop never goes unnoticed.
     *
     * @param array<string, mixed> $extensionSchema
     * @param array<string, mixed> $siteSchema
     * @param string $file the site file, named in the errors
     * @return array<string, mixed>
     */
    public static function merge(array $extensionSchema, array $siteSchema, string $file = ''): array
    {
        $key = (string) ($extensionSchema['key'] ?? '');
        $fail = static fn (string $what): FrontendConfigException => new FrontendConfigException(
            "Model \"{$key}\" extends the extension type: {$what}" . ($file !== '' ? " ({$file})" : ''),
        );
        foreach (self::LOCKED as $option) {
            if (array_key_exists($option, $siteSchema) && $siteSchema[$option] !== ($extensionSchema[$option] ?? null)) {
                throw $fail("\"{$option}\" can't be changed");
            }
        }
        $unknown = array_diff(array_keys($siteSchema), [...self::LOCKED, ...self::PATCHABLE]);
        if (array_key_exists('fields', $siteSchema) && !is_array($siteSchema['fields'])) {
            $unknown[] = 'fields';
        }
        if (array_key_exists('list', $siteSchema)) {
            $unknown = [...$unknown, ...(is_array($siteSchema['list']) ? array_map(static fn (int|string $k): string => 'list.' . $k, array_keys(array_diff_key($siteSchema['list'], ['columns' => true]))) : ['list'])];
        }
        if ($unknown !== []) {
            $named = implode(', ', array_map(static fn (int|string $k): string => '"' . $k . '"', $unknown));
            throw $fail("{$named} can't be set in an extends file (only key, extends, fields, list.columns and unchanged " . implode(', ', array_diff(self::LOCKED, ['key'])) . ')');
        }

        $merged = $extensionSchema;
        $fields = is_array($extensionSchema['fields'] ?? null) ? $extensionSchema['fields'] : [];
        foreach (is_array($siteSchema['fields'] ?? null) ? $siteSchema['fields'] : [] as $name => $field) {
            if (array_key_exists($name, $fields)) {
                throw $fail("field \"{$name}\" is already defined by the extension");
            }
            // "after": placement in the field order (the edit form's), not field config.
            $after = is_array($field) ? ($field['after'] ?? null) : null;
            if ($after === null) {
                $fields[$name] = $field;
                continue;
            }
            unset($field['after']);
            $position = is_string($after) ? array_search($after, array_keys($fields), true) : false;
            if ($position === false) {
                throw $fail("field \"{$name}\" goes \"after\" \"" . (is_string($after) ? $after : get_debug_type($after)) . '", which the type does not have (yet)');
            }
            $fields = array_slice($fields, 0, $position + 1, true) + [$name => $field] + array_slice($fields, $position + 1, null, true);
        }
        $merged['fields'] = $fields;

        if (is_array($siteSchema['list'] ?? null) && array_key_exists('columns', $siteSchema['list'])) {
            $merged['list'] = ['columns' => $siteSchema['list']['columns']] + (is_array($extensionSchema['list'] ?? null) ? $extensionSchema['list'] : []);
        }

        return $merged;
    }
}
