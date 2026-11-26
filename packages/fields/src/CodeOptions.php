<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Values\Code;

/**
 * A code field's option, top-level in its config:
 *
 *     "snippet": {"type": "code", "language": "php"}
 *
 * - "language": the language of the code in this field, one per field (a short name: letters,
 *   digits and + # . _ -). Folio's editor shows it beside the label; code_html() uses it as the
 *   class "language-php". Without it the code has no language class.
 *
 * @since 0.91.0
 */
final readonly class CodeOptions
{
    public function __construct(
        public ?string $language = null,
    ) {}

    /** @param array<string, mixed> $config the field's config */
    public static function fromConfig(array $config): self
    {
        $language = $config['language'] ?? null;

        return new self(is_string($language) && Code::isLanguage($language) ? $language : null);
    }

    /**
     * @param array<string, mixed> $config the field's config (its "translatable" too)
     * @throws \LogicException
     */
    public static function check(array $config): void
    {
        FieldOptions::refuseTranslatable('code', $config);
        FieldOptions::refuseNested('code', $config, ['language']);
        if (array_key_exists('language', $config)) {
            $language = $config['language'];
            if (!is_string($language) || !Code::isLanguage($language)) {
                throw new \LogicException('"language" is ' . json_encode($language) . '; use a short name such as php, javascript or c++ (letters, digits and + # . _ -).');
            }
        }
    }
}
