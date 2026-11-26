<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Fields\DateOptions;
use Preflow\Fields\Values\DateTimeValue;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\HasColumnKind;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\AdminFormat;
use Preflow\Folio\View\InlineIcon;

/**
 * A date with a time (0.91; the value, check and storage are preflow/fields': Values\DateTimeValue,
 * DateOptions and the core rule ValidDateTime, registered with FieldKinds::rules()). Stored as UTC
 * ISO 8601 text ("2026-03-29T12:30:00Z"), which sorts as text on every storage; the editor types and reads it in the
 * field's "zone", else the site's (app.timezone), and says which under the input.
 *
 * A time the clocks skip (spring forward) is kept as typed and refused by the check, naming the time
 * and the zone; a time that happens twice (fall back) is the earlier instant. Empty is null.
 *
 * The check is the core's: rules() adds none.
 */
final class DateTimeFieldType extends AbstractScalarFieldType implements DisplaysInAdmin, HasColumnKind
{
    public function key(): string
    {
        return 'datetime';
    }

    public function columnKind(array $config): ColumnKind
    {
        return ColumnKind::Date;
    }

    protected function inputType(): string
    {
        return 'datetime-local';
    }

    /**
     * The datetime-local input in the date box (calendar icon, clear button) with a hint under it:
     * the zone, and with script how far away the time is.
     */
    public function renderEditor(FieldContext $ctx): string
    {
        $e = self::escaper();
        $options = DateOptions::fromConfig($ctx->config);
        $zone = $options->zone();
        $value = self::inputValue($ctx->value, $zone);
        $hintId = $ctx->name . '-hint';
        $fieldOptions = [
            'type' => 'datetime-local',
            'value' => $value,
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
            'attrs' => ['aria-describedby' => $hintId],
        ];
        if ($ctx->label !== null) {
            $fieldOptions['label'] = $ctx->label;
        }
        $clear = FolioText::get('folio.fields.date_clear', ['label' => $ctx->label ?? $this->renderer->humanize($ctx->name)]);
        $control = static fn (string $input): string => '<div class="folio-date-input" data-folio-date>' . InlineIcon::svg('ui-calendar') . $input
            . '<button type="button" class="btn btn-ghost btn-icon btn-sm" data-folio-date-clear aria-label="' . $e($clear) . '" title="' . $e($clear) . '"' . ($value === '' ? ' hidden' : '') . '>'
            . InlineIcon::svg('ui-x') . '</button></div>'
            . '<p class="folio-date-hint" id="' . $e($hintId) . '" data-folio-date-hint data-zone="' . $e($zone->getName()) . '">' . $e($zone->getName()) . '</p>';

        return $this->renderDecorated($ctx->name, $fieldOptions, $control, '', 'folio-date-field');
    }

    /** The local value for the input: the stored instant in the zone, or what was typed (when it was refused). */
    private static function inputValue(mixed $value, \DateTimeZone $zone): string
    {
        return DateTimeValue::fromStored($value)?->inputValue($zone) ?? (is_string($value) ? str_replace(' ', 'T', trim($value)) : '');
    }

    /**
     * The stored form of what was typed in the field's zone ("2026-03-29T14:30" from the browser;
     * "2026-03-29 14:30" is read too); null when empty. A time that is no date and time, or does not
     * exist in the zone, stays as typed so the check can say so.
     */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);

        return DateTimeValue::fromLocal($raw, DateOptions::fromConfig($config)->zone())?->toStored()
            ?? $raw;
    }

    /** The stored UTC text; text that is no date and time is kept as typed (the check refuses it); null for none. */
    public function toStorage(mixed $value): mixed
    {
        return DateTimeValue::fromStored($value)?->toStored() ?? (is_string($value) && trim($value) !== '' ? trim($value) : null);
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->toStorage($value);
    }

    /** `<time datetime="2026-03-29T12:30:00Z">2026-03-29 14:30</time>`, the text in the field's zone. */
    public function renderFrontend(mixed $value, array $config): string
    {
        $datetime = DateTimeValue::fromStored($value);
        if ($datetime === null) {
            return '';
        }
        $e = self::escaper();

        return '<time datetime="' . $e($datetime->toStored()) . '">' . $e($datetime->format('Y-m-d H:i', DateOptions::fromConfig($config)->zone())) . '</time>';
    }

    /**
     * The date and time in the interface language (AdminFormat, folio.format.datetime), in the field's zone.
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $datetime = DateTimeValue::fromStored($value);
        if ($datetime === null) {
            return self::escaper()(is_string($value) ? $value : '');
        }

        return self::escaper()((new AdminFormat())->date($datetime->inZone(new \DateTimeZone('UTC')), 'datetime', DateOptions::fromConfig($config)->zone()));
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return ['fields.js'];
    }
}
