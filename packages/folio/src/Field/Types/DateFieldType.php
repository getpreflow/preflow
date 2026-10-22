<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;

/**
 * A date ("Y-m-d"), or date and time ("Y-m-d\TH:i") with "time": true.
 * Stored as the string the browser input produces; empty is allowed unless required.
 */
final class DateFieldType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'date';
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
     * "22 Oct 2026", or "22 Oct 2026, 23:15" with "time": true. Reads "Y-m-d", "Y-m-d\TH:i" and ISO 8601
     * with an offset (shown in the app timezone); a value that does not parse is shown escaped as it is.
     */
    public function renderFrontend(mixed $value, array $config): string
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';
        $text = $raw;
        if ($raw !== '') {
            try {
                $date = new \DateTimeImmutable($raw);
                if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $raw) === 1) {
                    $date = $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                }
                $text = $date->format(($config['time'] ?? false) === true ? 'j M Y, H:i' : 'j M Y');
            } catch (\Exception) {
                // not a date: shown as stored
            }
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function rules(array $config): array
    {
        return ($config['time'] ?? false) === true
            ? ['regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/']
            : ['regex:/^\d{4}-\d{2}-\d{2}$/'];
    }
}
