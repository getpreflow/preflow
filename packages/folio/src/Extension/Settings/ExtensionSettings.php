<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Settings;

use Preflow\Data\LocaleValue;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\Singletons;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\ExtensionDefinition;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Field\FieldTypeRegistry;

/**
 * Live extension settings: Extension::settings() as the singleton type "{name}_settings"
 * (section Settings, no publishing), edited on the site and read here with the declared
 * defaults. Values come from the working record, read through the field type (a boolean
 * stored as '' or '0' reads false); only null counts as not set. Translatable ones follow
 * the content fallback chain. An "env" field reads its environment variable.
 */
final class ExtensionSettings
{
    public function __construct(
        private readonly ExtensionRegistry $extensions,
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $types,
        private readonly Singletons $singletons,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly ContentLocale $contentLocale,
        private readonly bool $debug,
    ) {}

    public static function typeKey(string $extension): string
    {
        return $extension . '_settings';
    }

    /**
     * The settings type of an extension, as a model JSON schema, labelled "{label} settings".
     *
     * @param array<string, array<string, mixed>> $fields Extension::settings()
     * @return array<string, mixed>
     */
    public static function schema(ExtensionDefinition $definition, array $fields): array
    {
        if ($fields === []) {
            throw new ExtensionException("Extension \"{$definition->name}\" has a settings part but settings() returns no fields.");
        }
        foreach ($fields as $name => $field) {
            if (!is_string($name) || !is_array($field)) {
                throw new ExtensionException("{$definition->class}::settings() must map field names to field definitions.");
            }
            if (($field['type'] ?? null) === 'env' && (!is_string($field['env'] ?? null) || $field['env'] === '')) {
                throw new ExtensionException("Setting \"{$name}\" of extension \"{$definition->name}\" is an env field and needs \"env\": the variable's name.");
            }
        }

        return [
            'key' => self::typeKey($definition->name),
            'label' => $definition->label . ' settings', // never reads like the extension's own types
            'singleton' => true,
            'publishing' => false,
            'table' => 'folio_settings_' . $definition->name,
            'storage' => 'default',
            'fields' => $fields,
        ];
    }

    /**
     * The stored value, else the field's default, else null; an unknown key throws in debug.
     * $exact: a translatable setting reads only $locale's own value and default, never another
     * locale's (a text the caller would rather word itself, e.g. from lang files, than show in
     * the wrong language).
     */
    public function get(string $extension, string $key, ?string $locale = null, bool $exact = false): mixed
    {
        $type = self::typeKey($extension);
        if (!$this->catalog->has($type)) {
            try {
                $declared = $this->extensions->get($extension)->hasPart('settings');
            } catch (ExtensionException) {
                $declared = false;
            }
            if ($declared) {
                return null; // switched off: the extension or its settings part is disabled
            }

            return $this->unknown("Extension \"{$extension}\" has no settings.");
        }
        $typeDef = $this->types->get($type);
        $field = $typeDef->fields[$key] ?? null;
        if ($field === null) {
            return $this->unknown("Extension \"{$extension}\" has no setting \"{$key}\".");
        }
        if ($field->type === 'env') {
            return getenv((string) $field->config['env']) ?: null;
        }

        $locales = $typeDef->locales;
        $locale ??= $this->contentLocale->get();
        $read = static fn (mixed $raw): mixed => match (true) {
            !$field->translatable => LocaleValue::default($raw, $locales),
            $exact => ($own = LocaleValue::pick($raw, $locales, $locale)) === '' ? null : $own,
            default => LocaleValue::resolve($raw, $locales, $locale)[0],
        };

        $value = $read($this->singletons->record($type)?->get($key));
        if ($value !== null) {
            // '' is a real value: a cleared string, or false as SQL storage hands it back.
            // A field type may still read it as no value (an emptied number): then the default applies.
            $value = $this->fieldTypes->get($field->type)->fromStorage($value);
            if ($value !== null) {
                return $value;
            }
        }

        return $read($field->config['default'] ?? null);
    }

    private function unknown(string $message): null
    {
        if ($this->debug) {
            throw new ExtensionException($message);
        }

        return null;
    }
}
