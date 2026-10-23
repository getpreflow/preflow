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
        $patches = [];
        foreach (is_dir($modelsPath) ? (glob($modelsPath . '/*.json') ?: []) : [] as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            if (self::isPatch($schema)) {
                $key = is_string($schema['key'] ?? null) && $schema['key'] !== '' ? $schema['key'] : basename($file, '.json');
                $patches[$key] = $schema;
            }
        }

        return $patches;
    }

    /**
     * The extension's schema with the site's fields appended and its list columns, when given, in place of the extension's.
     *
     * @param array<string, mixed> $extensionSchema
     * @param array<string, mixed> $siteSchema
     * @return array<string, mixed>
     */
    public static function merge(array $extensionSchema, array $siteSchema): array
    {
        $key = (string) ($extensionSchema['key'] ?? '');
        foreach (self::LOCKED as $option) {
            if (array_key_exists($option, $siteSchema) && $siteSchema[$option] !== ($extensionSchema[$option] ?? null)) {
                throw new FrontendConfigException("Model \"{$key}\" extends the extension type: \"{$option}\" can't be changed");
            }
        }

        $merged = $extensionSchema;
        $fields = is_array($extensionSchema['fields'] ?? null) ? $extensionSchema['fields'] : [];
        foreach (is_array($siteSchema['fields'] ?? null) ? $siteSchema['fields'] : [] as $name => $field) {
            if (array_key_exists($name, $fields)) {
                throw new FrontendConfigException("Model \"{$key}\" extends the extension type: field \"{$name}\" is already defined by the extension");
            }
            $fields[$name] = $field;
        }
        $merged['fields'] = $fields;

        if (is_array($siteSchema['list'] ?? null) && array_key_exists('columns', $siteSchema['list'])) {
            $merged['list'] = ['columns' => $siteSchema['list']['columns']] + (is_array($extensionSchema['list'] ?? null) ? $extensionSchema['list'] : []);
        }

        return $merged;
    }
}
