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
 * Every write checks the stored shapes (storedRules(), since 0.92.1); Folio's form checks the
 * shapes its input posts (rules()).
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
        $value = (string) ($ctx->value ?? '');
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
     * "Y-m-d\TH:i:s.uP", "Y-m-d\TH:iP", as PHP parses them). Surrounding spaces are ignored.
     *
     * @since 0.92.1
     */
    public static function read(string $value): ?\DateTimeImmutable
    {
        $raw = trim($value);
        $zone = new \DateTimeZone(date_default_timezone_get());
        foreach (['Y-m-d|' => 'Y-m-d', 'Y-m-d\TH:i|' => 'Y-m-d\TH:i', 'Y-m-d\TH:i:s|' => 'Y-m-d\TH:i:s'] as $format => $shape) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw, $zone);
            if ($date !== false && $date->format($shape) === $raw) {
                return $date;
            }
        }
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:iP'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($date !== false) {
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
