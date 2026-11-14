<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;

/**
 * A yes/no checkbox. A hidden "0" sibling makes an unchecked box submit, and a
 * missing value normalizes to false, so unchecking always saves false.
 */
final class BooleanFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\DisplaysInAdmin, \Preflow\Folio\Field\HasColumnKind
{
    public function key(): string
    {
        return 'boolean';
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        return \Preflow\Data\Schema\ColumnKind::Boolean;
    }

    protected function inputType(): string
    {
        return 'checkbox';
    }

    /**
     * The box sits inline in its label (label text after it), help and every error below:
     * a lone checkbox on a line of its own under a label line reads as a missing control.
     */
    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $name = $e($ctx->name);
        $checked = $this->normalizeInput($ctx->value, $ctx->config) ? ' checked' : '';

        $switch = self::display($ctx->config) === 'switch';

        $html = '<input type="hidden" name="' . $name . '" value="0">'
            . '<div class="form-group folio-boolean' . ($switch ? ' folio-boolean-switch' : '') . ($ctx->errors !== [] ? ' has-error' : '') . '">' . "\n"
            . '  <label class="form-check" for="' . $name . '"><input type="checkbox"' . ($switch ? ' role="switch" class="folio-switch"' : '') . ' name="' . $name . '" id="' . $name . '" value="1"' . $checked . '>'
            . '<span class="folio-boolean-label">' . $e($ctx->label ?? $this->renderer->humanize($ctx->name)) . '</span></label>' . "\n";
        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        foreach ($ctx->errors as $error) {
            $html .= '  <div class="form-error">' . $e((string) $error) . '</div>' . "\n";
        }

        return $html . '</div>';
    }

    /**
     * The "display" option: "checkbox" (the default) or "switch", for a setting that acts at once
     * (board LibFields; a checkbox with role="switch", so it still posts like one).
     *
     * @since 0.75
     *
     * @param array<string, mixed> $config
     * @throws \LogicException for another value (TypeCatalog reports it at boot)
     */
    public static function display(array $config): string
    {
        $display = $config['display'] ?? 'checkbox';
        if (!in_array($display, ['checkbox', 'switch'], true)) {
            throw new \LogicException('Boolean field option "display" must be "checkbox" or "switch".');
        }

        return $display;
    }

    public function normalizeInput(mixed $raw, array $config): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(is_string($raw) ? strtolower($raw) : $raw, ['1', 'on', 'true', 1], true);
    }

    public function fromStorage(mixed $value): bool
    {
        return $this->normalizeInput($value, []);
    }

    /** Yes / No in the interface language; nothing stored (null or '') shows as empty rather than "No". */
    public function renderAdmin(mixed $value, array $config): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return \Preflow\Folio\FolioText::get($this->normalizeInput($value, $config) ? 'folio.common.yes' : 'folio.common.no');
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return $this->normalizeInput($value, $config) ? 'Yes' : 'No';
    }
}
