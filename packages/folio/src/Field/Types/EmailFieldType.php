<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Fields\Values\Email;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\SearchableText;

/**
 * An email address (0.91; the value, check and storage are preflow/fields': Values\Email and the
 * core rule "email", registered with FieldKinds::rules()). One line, the browser's email keyboard;
 * stored as the trimmed text, found by loose search like any text. The page gets
 * `<a href="mailto:…">`; the template function mailto_href(value) gives the address alone.
 *
 * The check is the core's: rules() adds none.
 */
final class EmailFieldType extends AbstractScalarFieldType implements SearchableText
{
    public function key(): string
    {
        return 'email';
    }

    /** The core value of this type (used without kinds, or when they hold none for the key). */
    protected function defaultValue(): \Preflow\Data\Values\FieldValue
    {
        return new \Preflow\Fields\Values\Field\EmailValue();
    }

    protected function inputType(): string
    {
        return 'email';
    }

    protected function editorAttrs(FieldContext $ctx): array
    {
        return ['autocomplete' => 'off', 'spellcheck' => 'false', 'inputmode' => 'email'];
    }

    /** The posted text (nothing posted is ""), as the core stores it (EmailValue: trimmed). */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return $this->stored(is_string($raw) ? $raw : '', $config);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $email = Email::fromStored($value);
        if ($email === null) {
            return '';
        }
        $e = self::escaper();
        $href = $email->href();

        return $href === null ? $e($email->address) : '<a href="' . $e($href) . '">' . $e($email->address) . '</a>';
    }
}
