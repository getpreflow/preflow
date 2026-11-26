<?php

declare(strict_types=1);

namespace Preflow\Fields\Rules;

use Preflow\Fields\CodeOptions;
use Preflow\Fields\Values\Code;
use Preflow\Fields\Values\Link;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A code field's value (the stored object {code, language}, JSON text of it, or plain code text)
 * has code text, and a language that is one the field offers ("languages"), else a short name
 * (Code::isLanguage()). A missing language passes. An empty value passes ("required" decides).
 *
 * FieldsServiceProvider registers it for every "code" field (FieldKinds::rules()).
 *
 * @since 0.91.0
 */
final class ValidCode implements ValidationRule
{
    public function __construct(
        private readonly CodeOptions $options = new CodeOptions(),
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        if (is_string($value) && !str_starts_with(ltrim($value), '{')) {
            return true;
        }
        $data = Link::decode($value);
        if ($data === null || !is_string($data['code'] ?? null) || $data['code'] === '') {
            return new Message('validation.required');
        }
        $language = $data['language'] ?? null;
        if ($language === null || $language === '') {
            return true;
        }
        if (!is_string($language)) {
            return new Message('validation.code_language');
        }
        if ($this->options->languages !== null) {
            return in_array($language, $this->options->languages, true) ? true : new Message('validation.in', ['values' => implode(', ', $this->options->languages)]);
        }

        return Code::isLanguage($language) ? true : new Message('validation.code_language');
    }
}
