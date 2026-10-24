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
    /** A multi relation shows a filter input above this many options. */
    private const FILTER_THRESHOLD = 12;

    /** Up to this many target records the picker lists them all; above, it searches. */
    public const INLINE_LIMIT = 200;

    /** Results per page of the search endpoint. */
    public const PAGE_SIZE = 20;

    /** Upper bound for the requested page, so (page - 1) * PAGE_SIZE can never overflow an int. */
    private const MAX_PAGE = 100000;

    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeRegistry $registry,
        private readonly ?\Preflow\Folio\Content\ContentLocale $contentLocale = null,
        private readonly ?\Preflow\Folio\Content\LiveContent $live = null,
        /** @var (\Closure(string): bool)|null permission check; null allows everything */
        private readonly ?\Closure $can = null,
        /** The admin prefix: the large-target picker searches {prefix}/_relation/{type}. Null keeps every picker inline. */
        private readonly ?string $prefix = null,
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

        if ($this->isLarge($cfg)) {
            return $this->renderRemote($ctx, $cfg, $selected, $label, $hasError);
        }

        $options = $this->options($cfg);
        if ($this->can !== null && $cfg['to'] !== '' && !($this->can)($cfg['to'] . '.view')) {
            // No access to the target type: list nothing else, keep what is linked (saving keeps it).
            $options = array_intersect_key($options, array_flip($selected));
        }

        if ($cfg['multiple']) {
            $html = '<fieldset class="form-group folio-relation folio-relation-multi' . ($hasError ? ' has-error' : '') . '" data-folio-relation-multi>' . "\n";
            $html .= '  <legend>' . $e($label);
            if ($ctx->required) {
                $html .= ' <span class="form-required">*</span>';
            }
            $html .= '</legend>' . "\n";
            if (count($options) > self::FILTER_THRESHOLD) {
                // No name: the filter is never posted.
                $html .= '  <input type="search" class="folio-relation-filter" data-folio-relation-filter'
                    . ' placeholder="Filter…" aria-label="' . $e('Filter ' . $label) . '" autocomplete="off">' . "\n";
            }
            $html .= '  <div class="folio-chips">' . "\n";
            foreach ($options as $id => $optLabel) {
                $chk = in_array((string) $id, $selected, true) ? ' checked' : '';
                $html .= '    <label class="folio-chip"><input type="checkbox" name="' . $e($name . '[]') . '" value="' . $e((string) $id) . '"' . $chk . '>'
                    . '<span class="folio-chip-label">' . $e($optLabel) . '</span></label>' . "\n";
            }
            $html .= '  </div>' . "\n";
        } else {
            $html = '<div class="form-group folio-relation' . ($hasError ? ' has-error' : '') . '">' . "\n";
            $html .= '  <label for="' . $e($name) . '">' . $e($label);
            if ($ctx->required) {
                $html .= ' <span class="form-required">*</span>';
            }
            $html .= '</label>' . "\n";
            $html .= '  <select name="' . $e($name) . '" id="' . $e($name) . '">' . "\n";
            $html .= '    <option value="">— none —</option>' . "\n";
            foreach ($options as $id => $optLabel) {
                $sel = in_array((string) $id, $selected, true) ? ' selected' : '';
                $html .= '    <option value="' . $e((string) $id) . '"' . $sel . '>' . $e($optLabel) . '</option>' . "\n";
            }
            $html .= '  </select>' . "\n";
        }

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }
        $html .= $cfg['multiple'] ? '</fieldset>' : '</div>';

        return $html;
    }

    /**
     * The large-target editor: only the linked records (labels loaded in one query) as removable
     * chips with hidden inputs under the usual input names, plus a search box that the admin script
     * wires to the endpoint. Without script the linked records still submit unchanged.
     *
     * @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string} $cfg
     * @param string[] $selected
     */
    private function renderRemote(FieldContext $ctx, array $cfg, array $selected, string $label, bool $hasError): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $name = $ctx->name;
        $multiple = $cfg['multiple'];
        $inputName = $multiple ? $name . '[]' : $name;
        $labels = $this->labelsFor($cfg, $selected);
        $canSearch = $this->can === null || ($this->can)($cfg['to'] . '.view');

        $query = [];
        if ($cfg['labelField'] !== null) {
            $query['label'] = $cfg['labelField'];
        }
        if ($cfg['orderBy'] !== null) {
            $query['order'] = $cfg['orderBy'];
        }
        $url = $this->prefix . '/_relation/' . rawurlencode($cfg['to']) . ($query !== [] ? '?' . http_build_query($query) : '');

        $class = 'form-group folio-relation folio-relation-remote' . ($multiple ? ' folio-relation-multi' : '') . ($hasError ? ' has-error' : '');
        $required = $ctx->required ? ' <span class="form-required">*</span>' : '';
        $html = '<fieldset class="' . $class . '" data-folio-relation-remote data-folio-relation-name="' . $e($inputName) . '"'
            . ($multiple ? ' data-folio-relation-multiple' : '') . '>' . "\n";
        $html .= '  <legend>' . $e($label) . $required . '</legend>' . "\n";
        $html .= '  <div class="folio-chips" data-folio-relation-selected>' . "\n";
        if (!$multiple && $selected === []) {
            $html .= '    <input type="hidden" name="' . $e($inputName) . '" value="">' . "\n";
        }
        foreach ($selected as $id) {
            $text = $labels[$id] ?? $id;
            $html .= '    <span class="folio-chip" data-folio-relation-item><span class="folio-chip-label">' . $e($text) . '</span>'
                . '<input type="hidden" name="' . $e($inputName) . '" value="' . $e($id) . '">'
                . '<button type="button" class="folio-chip-remove" data-folio-relation-remove aria-label="' . $e('Remove ' . $text) . '">×</button></span>' . "\n";
        }
        $html .= '  </div>' . "\n";
        if ($canSearch) {
            $html .= '  <input type="search" class="folio-relation-filter" data-folio-relation-search data-folio-relation-url="' . $e($url) . '"'
                . ' placeholder="Search…" aria-label="' . $e('Search ' . $label) . '" autocomplete="off">' . "\n";
            $html .= '  <ul class="folio-relation-results" data-folio-relation-results hidden></ul>' . "\n";
        }
        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }

        return $html . '</fieldset>';
    }

    /**
     * One page of the search endpoint: loose search over the target (a blank term lists all), in the
     * picker's order, then by id. Labels follow the picker's rule (labelField, else the first string
     * field that is not hidden).
     *
     * $labelField and $orderBy come from the request, so they are only used when a relation field
     * pointing at $to declares that very labelField / orderBy, and never when the field is hidden;
     * anything else is ignored (no hidden value can be read or sorted on through here).
     *
     * @return array{items: list<array{id: string, label: string}>, more: bool}
     */
    public function page(string $to, ?string $labelField, ?string $orderBy, string $term, int $page): array
    {
        if ($to === '' || !$this->registry->has($to)) {
            return ['items' => [], 'more' => false];
        }
        $def = $this->registry->get($to);
        $declared = $this->declaredFor($to);
        $cfg = [
            'to' => $to,
            'multiple' => false,
            'labelField' => $labelField !== null && in_array($labelField, $declared['labelField'], true) ? $labelField : null,
            'orderBy' => $orderBy !== null && in_array($orderBy, $declared['orderBy'], true) ? $orderBy : null,
        ];
        $query = $this->dm->queryType($to);
        if ($cfg['orderBy'] !== null) {
            $query->orderBy($cfg['orderBy'], numeric: $def->fields[$cfg['orderBy']]->type === 'number');
        }
        $query->orderBy($def->idField); // a stable order: no record repeats or goes missing across pages
        $query->searchLoose($term);
        $result = $query->paginate(self::PAGE_SIZE, min(max(1, $page), self::MAX_PAGE));

        $items = [];
        foreach ($result->items as $record) {
            $id = $record->getId();
            if ($id !== null) {
                $items[] = ['id' => (string) $id, 'label' => $this->labelFor($record, $cfg)];
            }
        }

        return ['items' => $items, 'more' => $result->hasMore];
    }

    /**
     * The labelField / orderBy values relation fields pointing at $to declare, limited to existing
     * fields of $to that are not hidden.
     *
     * @return array{labelField: list<string>, orderBy: list<string>}
     */
    private function declaredFor(string $to): array
    {
        $target = $this->registry->get($to);
        $out = ['labelField' => [], 'orderBy' => []];
        foreach ($this->dm->relationsTo($to) as $relation) {
            $config = $this->registry->get($relation->type)->fields[$relation->field]->config['relation'] ?? [];
            foreach (['labelField', 'orderBy'] as $key) {
                $name = is_array($config) ? ($config[$key] ?? null) : null;
                if (is_string($name) && isset($target->fields[$name]) && !self::hidden($target->fields[$name])) {
                    $out[$key][] = $name;
                }
            }
        }

        return $out;
    }

    private static function hidden(\Preflow\Data\TypeFieldDefinition $field): bool
    {
        return ($field->config['hidden'] ?? false) === true;
    }

    /** More target records than the picker lists inline (counted with a limit, never loading them all). */
    private function isLarge(array $cfg): bool
    {
        if ($this->prefix === null || $cfg['to'] === '' || !$this->registry->has($cfg['to'])) {
            return false;
        }

        return count($this->dm->queryType($cfg['to'])->limit(self::INLINE_LIMIT + 1)->get()->items()) > self::INLINE_LIMIT;
    }

    /**
     * @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string} $cfg
     * @param string[] $ids
     * @return array<string, string> id => label
     */
    private function labelsFor(array $cfg, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $idField = $this->registry->get($cfg['to'])->idField;
        $out = [];
        foreach ($this->dm->queryType($cfg['to'])->whereAny([['field' => $idField, 'operator' => 'IN', 'value' => $ids]])->get()->items() as $record) {
            $id = $record->getId();
            if ($id !== null) {
                $out[(string) $id] = $this->labelFor($record, $cfg);
            }
        }

        return $out;
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
        if ($ids === [] || $cfg['to'] === '' || !$this->registry->has($cfg['to'])) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $parts = [];
        foreach ($ids as $id) {
            $record = $this->live !== null ? $this->live->find($cfg['to'], $id) : $this->dm->findType($cfg['to'], $id);
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
     * @return array{to: string, multiple: bool, labelField: ?string, orderBy: ?string}
     */
    private function relationConfig(array $config): array
    {
        $r = is_array($config['relation'] ?? null) ? $config['relation'] : [];
        return [
            'to' => (string) ($r['to'] ?? ''),
            'multiple' => (bool) ($r['multiple'] ?? false),
            'labelField' => isset($r['labelField']) ? (string) $r['labelField'] : null,
            'orderBy' => isset($r['orderBy']) && $r['orderBy'] !== '' ? (string) $r['orderBy'] : null,
        ];
    }

    /**
     * @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string} $cfg
     * @return array<string, string> id => label
     */
    private function options(array $cfg): array
    {
        if ($cfg['to'] === '' || !$this->registry->has($cfg['to'])) {
            return [];
        }
        $out = [];
        $keys = [];
        foreach ($this->dm->queryType($cfg['to'])->get()->items() as $record) {
            $id = $record->getId();
            if ($id === null) {
                continue;
            }
            $out[(string) $id] = $this->labelFor($record, $cfg);
            if ($cfg['orderBy'] !== null) {
                $v = $record->localized($this->contentLocale?->get() ?? $record->getType()->locales->default)->get($cfg['orderBy']);
                $keys[(string) $id] = is_scalar($v) ? mb_strtolower((string) $v) : '';
            }
        }
        if ($cfg['orderBy'] !== null) {
            // Stable: equal keys keep their stored order.
            $pos = array_flip(array_keys($out));
            uksort($out, static fn ($a, $b) => ($keys[$a] <=> $keys[$b]) ?: ($pos[$a] <=> $pos[$b]));
        }
        return $out;
    }

    /** @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string} $cfg */
    private function labelFor(DynamicRecord $record, array $cfg): string
    {
        $localized = $record->localized($this->contentLocale?->get() ?? $record->getType()->locales->default);
        $labelDef = $cfg['labelField'] !== null && $this->registry->has($cfg['to']) ? ($this->registry->get($cfg['to'])->fields[$cfg['labelField']] ?? null) : null;
        if ($labelDef !== null && !self::hidden($labelDef)) {
            $v = $localized->get($cfg['labelField']);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        $field = $this->firstStringField($cfg['to']);
        if ($field !== null) {
            $v = $localized->get($field);
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
            if ($def->type === 'string' && !self::hidden($def)) {
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
