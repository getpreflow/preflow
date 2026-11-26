<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Values\Code;

/**
 * A code field's options, top-level in its config:
 *
 *     "snippet": {"type": "code", "languages": ["php", "javascript", "html"], "default_language": "html"}
 *
 * - "languages": the languages an editor chooses from; a save refuses another one. Without it any
 *   short name is accepted (Code::isLanguage()) and the editor types it.
 * - "default_language": the language a new value starts with (one of "languages" when given).
 *
 * @since 0.91.0
 */
final readonly class CodeOptions
{
    /** @param list<string>|null $languages null: any short name */
    public function __construct(
        public ?array $languages = null,
        public ?string $defaultLanguage = null,
    ) {}

    /** @param array<string, mixed> $config the field's config */
    public static function fromConfig(array $config): self
    {
        $languages = null;
        if (is_array($config['languages'] ?? null)) {
            $languages = array_values(array_filter($config['languages'], static fn (mixed $l): bool => is_string($l) && Code::isLanguage($l)));
        }
        $default = $config['default_language'] ?? null;

        return new self($languages === [] ? null : $languages, is_string($default) && Code::isLanguage($default) ? $default : null);
    }

    /**
     * @param array<string, mixed> $config the field's config (its "translatable" too)
     * @throws \LogicException
     */
    public static function check(array $config): void
    {
        FieldOptions::refuseTranslatable('code', $config);
        if (array_key_exists('languages', $config)) {
            $languages = $config['languages'];
            if (!is_array($languages) || !array_is_list($languages) || $languages === []) {
                throw new \LogicException('"languages" must be a list such as ["php", "javascript"].');
            }
            foreach ($languages as $language) {
                if (!is_string($language) || !Code::isLanguage($language)) {
                    throw new \LogicException('"languages" lists ' . json_encode($language) . '; use short names such as php, javascript or c++ (letters, digits and + # . _ -).');
                }
            }
        }
        if (array_key_exists('default_language', $config)) {
            $default = $config['default_language'];
            if (!is_string($default) || !Code::isLanguage($default)) {
                throw new \LogicException('"default_language" is ' . json_encode($default) . '; use a short name such as php.');
            }
            if (is_array($config['languages'] ?? null) && !in_array($default, $config['languages'], true)) {
                throw new \LogicException("\"default_language\" is \"{$default}\", which \"languages\" does not list; add it there or choose one of: " . implode(', ', array_filter($config['languages'], 'is_string')) . '.');
            }
        }
    }
}
