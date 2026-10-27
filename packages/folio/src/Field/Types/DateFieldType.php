<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Http\AdminFormat;

/**
 * A date ("Y-m-d"), or date and time ("Y-m-d\TH:i") with "time": true.
 * Stored as the string the browser input produces; empty is allowed unless required.
 */
final class DateFieldType extends AbstractScalarFieldType implements DisplaysInAdmin, \Preflow\Folio\Field\HasColumnKind
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

    public function renderEditor(FieldContext $ctx): string
    {
        $options = [
            'type' => ($ctx->config['time'] ?? false) === true ? 'datetime-local' : 'date',
            'value' => (string) ($ctx->value ?? ''),
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
        ];
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }

        return $this->renderer->renderField($ctx->name, $options);
    }

    /**
     * Admin display in the interface language (AdminFormat: folio.format.date, or .datetime with
     * "time": true; "Oct 22, 2026" / "22.10.2026"). Reads exactly "Y-m-d", "Y-m-d\TH:i"
     * and ISO 8601 with an offset (also with seconds; shown in the app timezone). Anything else is shown
     * escaped as it is. The frontend output (renderFrontend) stays the stored string.
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';
        $text = $raw;
        $zone = new \DateTimeZone(date_default_timezone_get());
        $date = false;
        foreach (['Y-m-d|', 'Y-m-d\TH:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw, $zone);
            if ($date !== false && $date->format($format === 'Y-m-d|' ? 'Y-m-d' : 'Y-m-d\TH:i') === $raw) {
                break;
            }
            $date = false;
        }
        if ($date === false) {
            foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:iP'] as $format) {
                $date = \DateTimeImmutable::createFromFormat($format, $raw);
                if ($date !== false) {
                    $date = $date->setTimezone($zone);
                    break;
                }
            }
        }
        if ($date !== false) {
            $text = (new AdminFormat())->date($date, ($config['time'] ?? false) === true ? 'datetime' : 'date');
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function rules(array $config): array
    {
        return ($config['time'] ?? false) === true
            ? ['regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/']
            : ['regex:/^\d{4}-\d{2}-\d{2}$/'];
    }
}
