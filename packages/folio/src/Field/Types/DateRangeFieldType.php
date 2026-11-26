<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Fields\DateOptions;
use Preflow\Fields\Values\DateRange;
use Preflow\Fields\Values\DateTimeValue;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\AdminFormat;
use Preflow\Folio\View\InlineIcon;

/**
 * A range of dates (0.91; the value, check and storage are preflow/fields': Values\DateRange,
 * DateOptions and the core rule ValidDateRange). Two inputs, "From" and "To"; stored as the object
 * {start, end}: dates ("2026-03-01"), or with "with_time": true date-times in UTC, typed and read in
 * the field's "zone" (default: the site's). The end must not be before the start; the check names
 * both. Both ends or none: a range with one end is refused.
 *
 * Not sortable by value (the list sorts the stored text).
 */
final class DateRangeFieldType implements FieldType, DisplaysInAdmin
{
    public function key(): string
    {
        return 'daterange';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $options = DateOptions::fromConfig($ctx->config);
        $zone = $options->zone();
        $parts = DateRange::parts($ctx->value) ?? ['start' => null, 'end' => null];
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name));
        $id = 'folio-daterange-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $ctx->name), '-');
        $hasError = $ctx->errors !== [];
        $type = $options->withTime ? 'datetime-local' : 'date';

        $html = '<fieldset class="form-group folio-daterange' . ($hasError ? ' has-error' : '') . '">' . "\n";
        $html .= '  <legend>' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</legend>' . "\n";
        $html .= '  <div class="folio-daterange-row">';
        foreach (['start' => 'from', 'end' => 'to'] as $end => $text) {
            $value = $parts[$end] ?? '';
            if ($options->withTime) {
                $value = DateTimeValue::fromStored($value)?->inputValue($zone) ?? str_replace(' ', 'T', $value);
            }
            $input = '<input type="' . $type . '" id="' . $e($id . '-' . $end) . '" name="' . $e($ctx->name . '[' . $end . ']') . '" value="' . $e($value) . '"' . ($options->withTime ? ' aria-describedby="' . $e($id . '-' . $end) . '-hint"' : '') . '>';
            if ($options->withTime) {
                // As the datetime field: the date box with the zone and, with script, how far away it is in that zone.
                $input = '<div class="folio-date-input" data-folio-date>' . InlineIcon::svg('ui-calendar') . $input . '</div>'
                    . '<p class="folio-date-hint" id="' . $e($id . '-' . $end) . '-hint" data-folio-date-hint data-zone="' . $e($zone->getName()) . '">' . $e($zone->getName()) . '</p>';
            }
            $html .= '<div class="form-group' . ($options->withTime ? ' folio-date-field' : '') . '"><label for="' . $e($id . '-' . $end) . '">' . $e(FolioText::get('folio.fields.daterange.' . $text)) . '</label>' . $input . '</div>';
        }
        $html .= "</div>\n";
        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) $ctx->errors[0]) . '</div>' . "\n";
        }

        return $html . '</fieldset>';
    }

    /**
     * {start, end}, with a time converted from the field's zone to UTC; null when both are empty.
     * An end that is no date, or a time the zone skips, stays as typed for the check to refuse.
     */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        $parts = DateRange::parts($raw);
        if ($parts === null) {
            return null;
        }
        $options = DateOptions::fromConfig($config);
        foreach ($parts as $end => $text) {
            if ($text !== null && $options->withTime) {
                $parts[$end] = DateTimeValue::fromLocal($text, $options->zone())?->toStored() ?? $text;
            }
        }

        return $parts;
    }

    /** {start, end} as it is (SQL storage writes JSON text of it): what is wrong with it is the check's to say. */
    public function toStorage(mixed $value): mixed
    {
        $parts = DateRange::parts($value);

        return $parts === null ? null : ['start' => $parts['start'], 'end' => $parts['end']];
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->toStorage($value);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $range = DateRange::fromStored($value);
        if ($range === null) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $zone = DateOptions::fromConfig($config)->zone();

        return '<time datetime="' . $e($range->start) . '">' . $e($range->format(false, $zone)) . '</time> – <time datetime="' . $e($range->end) . '">' . $e($range->format(true, $zone)) . '</time>';
    }

    /**
     * "start – end" in the interface language (AdminFormat), in the field's zone for a range with a time.
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $range = DateRange::fromStored($value);
        if ($range === null) {
            return '';
        }
        $zone = DateOptions::fromConfig($config)->zone();
        $format = new AdminFormat();
        $show = static fn (string $end): string => $range->hasTime()
            ? $format->date(DateTimeValue::fromStored($end)?->inZone(new \DateTimeZone('UTC')), 'datetime', $zone)
            : $format->date($end, 'date');

        return htmlspecialchars($show($range->start) . ' – ' . $show($range->end), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
