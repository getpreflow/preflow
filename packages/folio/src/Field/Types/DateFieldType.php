<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Http\AdminFormat;

/**
 * A date ("Y-m-d"), or date and time ("Y-m-d\TH:i", with seconds when the input has them) with
 * "time": true. The form stores the string the browser input produces; server code may also store
 * ISO 8601 with an offset (gmdate(DATE_ATOM)), which the type reads too (read()). Empty is
 * allowed unless required.
 *
 * The core's writes (saveType(), imports, seeds, the API) check the stored shapes (storedRules(),
 * since 0.92.1); Folio's checks (RecordValidator: its form, RecordWriter, publishing) check the
 * shapes its input posts (rules()), as since 0.91.
 */
final class DateFieldType extends AbstractScalarFieldType implements DisplaysInAdmin, \Preflow\Folio\Field\HasColumnKind, \Preflow\Folio\Field\HasStoredRules
{
    public function key(): string
    {
        return 'date';
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        return \Preflow\Data\Schema\ColumnKind::Date;
    }

    protected function inputType(): string
    {
        return 'date';
    }

    /**
     * The date input in a box with a calendar icon and a clear button (board LibFields), and a hint
     * under it: fields.js writes when the date is ("In 2 days"), in the admin language; a date with
     * a time adds the app's timezone, which is also what the hint says without script.
     */
    public function renderEditor(FieldContext $ctx): string
    {
        $e = self::escaper();
        $time = ($ctx->config['time'] ?? false) === true;
        $value = self::inputValue((string) ($ctx->value ?? ''), $time);
        $hintId = $ctx->name . '-hint';
        $options = [
            'type' => $time ? 'datetime-local' : 'date',
            'value' => $value,
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
            'attrs' => ['aria-describedby' => $hintId],
        ];
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }
        $clear = \Preflow\Folio\FolioText::get('folio.fields.date_clear', ['label' => $ctx->label ?? $this->renderer->humanize($ctx->name)]);
        $zone = $time ? date_default_timezone_get() : null;
        $control = static fn (string $input): string => '<div class="folio-date-input" data-folio-date>' . \Preflow\Folio\View\InlineIcon::svg('ui-calendar') . $input
            . '<button type="button" class="btn btn-ghost btn-icon btn-sm" data-folio-date-clear aria-label="' . $e($clear) . '" title="' . $e($clear) . '"' . ($value === '' ? ' hidden' : '') . '>'
            . \Preflow\Folio\View\InlineIcon::svg('ui-x') . '</button></div>'
            . '<p class="folio-date-hint" id="' . $e($hintId) . '" data-folio-date-hint' . ($zone !== null ? ' data-zone="' . $e($zone) . '">' . $e($zone) : '>') . '</p>';

        return $this->renderDecorated($ctx->name, $options, $control, '', 'folio-date-field');
    }

    /**
     * The stored value as the input shows it (since 0.92.1): a value the type reads in the input's
     * own shape, in the app timezone ("Y-m-d"; with "time" "Y-m-d\TH:i", or with its seconds). A
     * browser shows an input empty when its value has another shape (ISO 8601 with an offset that
     * server code stored), so a save without touching it cleared the date. Such a save now stores
     * the value in the form's shape: "2026-11-27T05:00:00+00:00" becomes "2026-11-27T06:00" with
     * "time" in Europe/Zurich, "2026-11-27" without. A value already in the input's shape is
     * shown as it is; one the type does not read, too.
     */
    private static function inputValue(string $value, bool $time): string
    {
        $date = self::read($value);
        if ($date === null) {
            return $value;
        }
        $shape = !$time ? 'Y-m-d' : ($date->format('s') === '00' ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s');
        return preg_match($time ? '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/' : '/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $date->format($shape);
    }

    /**
     * Admin display in the interface language (AdminFormat: folio.format.date, or .datetime with
     * "time": true; "Oct 22, 2026" / "22.10.2026") of a value read() reads (one with an offset is
     * shown in the app timezone). Anything else is shown escaped as it is. The frontend output
     * (renderFrontend) stays the stored string.
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';
        $date = self::read($raw);
        $text = $date !== null
            ? (new AdminFormat())->date($date, ($config['time'] ?? false) === true ? 'datetime' : 'date')
            : $raw;

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The date a stored value holds, in the app timezone, or null when the type does not read it.
     * It reads exactly "Y-m-d", "Y-m-d\TH:i" and "Y-m-d\TH:i:s" (what the inputs post; a real
     * calendar date and time, in the app timezone) and ISO 8601 with an offset ("Y-m-d\TH:i:sP",
     * "Y-m-d\TH:i:s.uP", "Y-m-d\TH:iP"), also a real date and time (since 0.92.1 no overflow:
     * "2026-02-30T05:00:00+00:00" or an hour 25 is not read). Nothing around the value: a padded
     * one (" 2026-11-27 ") is not read (renderAdmin() trims before it reads).
     *
     * @since 0.92.1
     */
    public static function read(string $value): ?\DateTimeImmutable
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        foreach (['Y-m-d|' => 'Y-m-d', 'Y-m-d\TH:i|' => 'Y-m-d\TH:i', 'Y-m-d\TH:i:s|' => 'Y-m-d\TH:i:s'] as $format => $shape) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, $zone);
            if ($date !== false && $date->format($shape) === $value) {
                return $date;
            }
        }
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:iP'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $problems = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return $date->setTimezone($zone);
            }
        }

        return null;
    }

    /** What every write checks (since 0.92.1): a value read() reads, with or without "time". */
    public function storedRules(array $config): array
    {
        return [new StoredDate()];
    }

    /**
     * What Folio's form checks: the shape its input posts ("Y-m-d", or with "time" "Y-m-d\TH:i"
     * and optional seconds).
     */
    public function rules(array $config): array
    {
        return ($config['time'] ?? false) === true
            ? ['regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/']
            : ['regex:/^\d{4}-\d{2}-\d{2}$/'];
    }
}
