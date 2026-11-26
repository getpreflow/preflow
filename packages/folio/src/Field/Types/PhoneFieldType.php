<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Fields\PhoneOptions;
use Preflow\Fields\Values\Phone;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\FolioText;
use Preflow\Form\FieldRenderer;

/**
 * A phone number (0.91; the value, check and storage are preflow/fields': Values\Phone, ValidPhone,
 * PhoneOptions). The editor types the number as people write it; a save keeps it as typed and adds
 * its E.164 form ("+41441234567") for the `tel:` link, using the field's "default_region" for a
 * number without a country code. Stored: {as_typed, e164}, or null for no number.
 *
 * Below the input the editor says how a saved number is dialled ("Dialled as +41441234567"). The
 * page gets `<a href="tel:…">` with the number as typed; tel_href(value) gives the address alone.
 *
 * The check is the core's (ValidPhone through FieldKinds::rules()): rules() adds none.
 */
final class PhoneFieldType implements FieldType, DisplaysInAdmin
{
    public function __construct(
        private readonly FieldRenderer $renderer = new FieldRenderer(),
    ) {}

    public function key(): string
    {
        return 'phone';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $phone = Phone::fromStored($ctx->value);
        $help = (string) ($ctx->help ?? '');
        if ($phone?->e164 !== null && $ctx->errors === []) {
            $dialled = FolioText::get('folio.fields.phone.dialled', ['number' => $phone->e164]);
            $help = $help === '' ? $dialled : $help . ' ' . $dialled;
        }
        $options = [
            'type' => 'tel',
            'value' => $phone->asTyped ?? (is_string($ctx->value) ? $ctx->value : ''),
            'help' => $help,
            'required' => $ctx->required,
            'errors' => $ctx->errors,
            'attrs' => ['autocomplete' => 'off', 'inputmode' => 'tel'] + ($ctx->placeholder !== null ? ['placeholder' => $ctx->placeholder] : []),
        ];
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }

        return $this->renderer->renderField($ctx->name, $options);
    }

    /** The typed number with its E.164 form for the field's default region; null for no number. */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return Phone::of($raw, PhoneOptions::fromConfig($config)->defaultRegion)->toStored();
    }

    /** The stored object as it is (SQL storage writes JSON text of it); null for no number. */
    public function toStorage(mixed $value): mixed
    {
        return Phone::fromStored($value)?->toStored();
    }

    public function fromStorage(mixed $value): mixed
    {
        return Phone::fromStored($value)?->toStored();
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $phone = Phone::fromStored($value);
        if ($phone === null) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $href = $phone->href();

        return $href === null ? $e($phone->asTyped) : '<a href="' . $e($href) . '">' . $e($phone->asTyped) . '</a>';
    }

    /**
     * The number as typed (list cells, read-only fields).
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        return htmlspecialchars(Phone::fromStored($value)->asTyped ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }
}
