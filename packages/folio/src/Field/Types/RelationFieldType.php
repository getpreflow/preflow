<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;

/**
 * Minimal relation field: a server-rendered picker over a target content type,
 * storing the selected target id(s). No data-layer relation model — ids are
 * resolved to records on demand for display.
 */
final class RelationFieldType implements FieldType
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeRegistry $registry,
    ) {}

    public function key(): string
    {
        return 'relation';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $cfg = $this->relationConfig($ctx->config);
        $name = $ctx->name;
        $selected = $this->toList($ctx->value);
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $name));
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $hasError = $ctx->errors !== [];

        $html = '<div class="form-group folio-relation' . ($hasError ? ' has-error' : '') . '">' . "\n";
        $html .= '  <label for="' . $e($name) . '">' . $e($label);
        if ($ctx->required) {
            $html .= ' <span class="form-required">*</span>';
        }
        $html .= '</label>' . "\n";

        $selectName = $cfg['multiple'] ? $name . '[]' : $name;
        $multipleAttr = $cfg['multiple'] ? ' multiple' : '';
        $html .= '  <select name="' . $e($selectName) . '" id="' . $e($name) . '"' . $multipleAttr . '>' . "\n";
        if (!$cfg['multiple']) {
            $html .= '    <option value="">— none —</option>' . "\n";
        }
        foreach ($this->options($cfg) as $id => $optLabel) {
            $sel = in_array((string) $id, $selected, true) ? ' selected' : '';
            $html .= '    <option value="' . $e((string) $id) . '"' . $sel . '>' . $e($optLabel) . '</option>' . "\n";
        }
        $html .= '  </select>' . "\n";

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }
        $html .= '</div>';

        return $html;
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if ($this->relationConfig($config)['multiple']) {
            return is_array($raw)
                ? array_values(array_filter($raw, static fn ($v) => is_string($v) && $v !== ''))
                : [];
        }
        return is_string($raw) ? $raw : '';
    }

    public function toStorage(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode(array_values($value), JSON_UNESCAPED_SLASHES);
        }
        return (string) ($value ?? '');
    }

    public function fromStorage(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $value;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $cfg = $this->relationConfig($config);
        $ids = $this->toList($value);
        if ($ids === [] || $cfg['to'] === '') {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $parts = [];
        foreach ($ids as $id) {
            $record = $this->dm->findType($cfg['to'], $id);
            if ($record === null) {
                continue;
            }
            $parts[] = '<span class="folio-relation-item">' . $e($this->labelFor($record, $cfg)) . '</span>';
        }
        return implode(', ', $parts);
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{to: string, multiple: bool, labelField: ?string}
     */
    private function relationConfig(array $config): array
    {
        $r = is_array($config['relation'] ?? null) ? $config['relation'] : [];
        return [
            'to' => (string) ($r['to'] ?? ''),
            'multiple' => (bool) ($r['multiple'] ?? false),
            'labelField' => isset($r['labelField']) ? (string) $r['labelField'] : null,
        ];
    }

    /**
     * @param array{to: string, multiple: bool, labelField: ?string} $cfg
     * @return array<string, string> id => label
     */
    private function options(array $cfg): array
    {
        if ($cfg['to'] === '' || !$this->registry->has($cfg['to'])) {
            return [];
        }
        $out = [];
        foreach ($this->dm->queryType($cfg['to'])->get()->items() as $record) {
            $id = $record->getId();
            if ($id === null) {
                continue;
            }
            $out[(string) $id] = $this->labelFor($record, $cfg);
        }
        return $out;
    }

    /** @param array{to: string, multiple: bool, labelField: ?string} $cfg */
    private function labelFor(DynamicRecord $record, array $cfg): string
    {
        if ($cfg['labelField'] !== null) {
            $v = $record->get($cfg['labelField']);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        $field = $this->firstStringField($cfg['to']);
        if ($field !== null) {
            $v = $record->get($field);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return (string) $record->getId();
    }

    private function firstStringField(string $type): ?string
    {
        if (!$this->registry->has($type)) {
            return null;
        }
        foreach ($this->registry->get($type)->fields as $name => $def) {
            if ($def->type === 'string') {
                return $name;
            }
        }
        return null;
    }

    /** @return string[] */
    private function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        return [];
    }
}
