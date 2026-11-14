<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;

/**
 * Relation field: the linked target id(s), edited as rows with a search (rows()). No data-layer
 * relation model: ids are resolved to records on demand for display.
 */
final class RelationFieldType implements FieldType, \Preflow\Folio\Field\HasColumnKind
{
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
        private readonly ?\Preflow\Publishing\LiveContent $live = null,
        /** @var (\Closure(string): bool)|null permission check; null allows everything */
        private readonly ?\Closure $can = null,
        /** The admin prefix: the large-target picker searches {prefix}/_relation/{type}. Null keeps every picker inline. */
        private readonly ?string $prefix = null,
        /** The types' families: the rows' initials take the target's tint (0.75). */
        private readonly ?\Preflow\Folio\Content\TypeCatalog $catalog = null,
    ) {}

    public function key(): string
    {
        return 'relation';
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        // A list of ids when the relation is multiple (relation.multiple, as the editor reads it), one id otherwise.
        return $this->relationConfig($config)['multiple'] ? \Preflow\Data\Schema\ColumnKind::Text : \Preflow\Data\Schema\ColumnKind::Short;
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $cfg = $this->relationConfig($ctx->config);
        $selected = $this->toList($ctx->value);
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name));
        $canView = $this->can === null || $cfg['to'] === '' || ($this->can)($cfg['to'] . '.view');
        $known = $cfg['to'] !== '' && $this->registry->has($cfg['to']);

        if ($this->isLarge($cfg)) {
            // Too many to list: the linked ones (one query), and the search endpoint for the rest.
            $entries = $this->entriesFor($cfg, $selected);
            $search = $canView ? ['url' => $this->searchUrl($cfg)] : null;
        } else {
            $all = $known ? $this->entries($cfg) : [];
            $entries = array_intersect_key($all, array_flip($selected));
            // No access to the target type: list nothing else, keep what is linked (saving keeps it).
            $search = $canView && $known ? ['options' => array_values(array_map(
                static fn (string $id, array $entry): array => ['id' => $id] + $entry,
                array_keys($all),
                $all,
            ))] : null;
        }

        return $this->rows($ctx, $cfg, $selected, $entries, $label, $search, $canView && $known);
    }

    /**
     * The relation editor (board LibFields): the linked records as rows (a grip when several can be
     * linked, the target's tinted initials, the label and its meta line, open in the drawer, remove),
     * under them a search row and its results with "Show more". fields.js runs the search: through
     * the embedded options of a small target, the search endpoint of a large one. Each row holds a
     * hidden input under the field's name, so the links submit unchanged without script.
     *
     * @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string, metaField: ?string} $cfg
     * @param list<string> $selected
     * @param array<string, array{label: string, meta: ?string}> $entries
     * @param array{url?: string, options?: list<array{id: string, label: string, meta: ?string}>}|null $search
     */
    private function rows(FieldContext $ctx, array $cfg, array $selected, array $entries, string $label, ?array $search, bool $canOpen): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $multiple = $cfg['multiple'];
        $inputName = $multiple ? $ctx->name . '[]' : $ctx->name;
        $family = $this->catalog?->get($cfg['to'])?->family?->value ?? '';
        $openPattern = $canOpen && $this->prefix !== null ? $this->prefix . '/' . rawurlencode($cfg['to']) . '/{id}/edit' : null;
        $hasError = $ctx->errors !== [];

        $html = '<fieldset class="form-group folio-relation' . ($multiple ? ' folio-relation-multi' : '') . ($hasError ? ' has-error' : '') . '" data-folio-relation'
            . ' data-folio-relation-name="' . $e($inputName) . '"' . ($multiple ? ' data-folio-relation-multiple' : '')
            . ($openPattern !== null ? ' data-folio-relation-open="' . $e($openPattern) . '"' : '') . '>' . "\n";
        $html .= '  <legend>' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</legend>' . "\n";
        $html .= '  <div class="folio-relation-box">' . "\n";
        $html .= '    <ul class="folio-relation-rows" data-folio-relation-selected>';
        if (!$multiple && $selected === []) {
            // An emptied single relation still posts its name, so removing the link saves.
            $html .= '<li hidden><input type="hidden" name="' . $e($inputName) . '" value=""></li>';
        }
        foreach ($selected as $id) {
            $entry = $entries[$id] ?? ['label' => $id, 'meta' => null];
            $html .= $this->row($id, $entry['label'], $entry['meta'], $inputName, $family, $multiple, $openPattern);
        }
        $html .= '</ul>' . "\n";
        if ($search !== null) {
            $find = \Preflow\Folio\FolioText::get('folio.fields.search_label', ['label' => $label]);
            $html .= '    <label class="folio-relation-search" data-folio-no-edit>' . \Preflow\Folio\View\InlineIcon::svg('ui-search')
                . '<input type="search" data-folio-relation-find'
                . (isset($search['url']) ? ' data-folio-relation-url="' . $e($search['url']) . '"' : '')
                . ' placeholder="' . $e(\Preflow\Folio\FolioText::get('folio.common.search_placeholder')) . '" aria-label="' . $e($find) . '" autocomplete="off"></label>' . "\n";
            if (isset($search['options'])) {
                $html .= '    <script type="application/json" data-folio-relation-options>' . json_encode($search['options'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
            }
            $html .= '    <ul class="folio-relation-results" data-folio-relation-results data-text-more="' . $e(\Preflow\Folio\FolioText::get('folio.fields.show_more')) . '"'
                . ' data-text-empty="' . $e(\Preflow\Folio\FolioText::get('folio.fields.no_results')) . '" hidden></ul>' . "\n";
            // The row fields.js copies for a picked record.
            $html .= '    <template data-folio-relation-template>' . $this->row('', '', null, $inputName, $family, $multiple, $openPattern) . '</template>' . "\n";
        }
        $html .= '  </div>' . "\n";
        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }

        return $html . '</fieldset>';
    }

    /** One linked record's row; with an empty id, the template fields.js fills. */
    private function row(string $id, string $label, ?string $meta, string $inputName, string $family, bool $multiple, ?string $openPattern): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $named = static fn (string $key): string => $e(\Preflow\Folio\FolioText::get($key, ['label' => $label]));
        $html = '<li class="folio-relation-row" data-folio-relation-item>';
        if ($multiple) {
            $html .= '<button type="button" class="folio-relation-grip" data-folio-sort-handle aria-label="' . $named('folio.fields.move_named') . '" title="' . $named('folio.fields.move_named') . '">'
                . \Preflow\Folio\View\InlineIcon::svg('ui-grip') . '</button>';
        }
        $html .= '<span class="folio-relation-avatar folio-type-icon" data-family="' . $e($family) . '" aria-hidden="true">' . $e(self::initials($label)) . '</span>'
            . '<span class="folio-relation-text"><span class="folio-relation-label">' . $e($label) . '</span>'
            . '<span class="folio-relation-meta"' . ($meta === null || $meta === '' ? ' hidden' : '') . '>' . $e((string) $meta) . '</span></span>';
        if ($openPattern !== null) {
            $html .= '<a class="folio-relation-action" href="' . $e(str_replace('{id}', rawurlencode($id), $openPattern)) . '" data-folio-relation-open-item aria-label="' . $named('folio.fields.open_named') . '" title="' . $named('folio.fields.open_named') . '">'
                . \Preflow\Folio\View\InlineIcon::svg('ui-external') . '</a>';
        }
        $html .= '<button type="button" class="folio-relation-action" data-folio-relation-remove aria-label="' . $named('folio.fields.remove_named') . '" title="' . $named('folio.fields.remove_named') . '">'
            . \Preflow\Folio\View\InlineIcon::svg('ui-x') . '</button>'
            . '<input type="hidden" name="' . $e($inputName) . '" value="' . $e($id) . '"></li>';

        return $html;
    }

    /** "Dubach Holzbau AG" → "DH": the first letters of the first two words. @since 0.75 */
    public static function initials(string $label): string
    {
        $words = preg_split('/[\s\-_.,·&\/]+/u', trim($label), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $letters !== '' ? $letters : '?';
    }

    /** @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string, metaField: ?string} $cfg */
    private function searchUrl(array $cfg): string
    {
        $query = [];
        if ($cfg['labelField'] !== null) {
            $query['label'] = $cfg['labelField'];
        }
        if ($cfg['orderBy'] !== null) {
            $query['order'] = $cfg['orderBy'];
        }
        if ($cfg['metaField'] !== null) {
            $query['meta'] = $cfg['metaField'];
        }

        return $this->prefix . '/_relation/' . rawurlencode($cfg['to']) . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * The targets a picker lists inline (id => label, in the picker's order), as a filter
     * select can offer them; null when the target has too many to list (the editor searches).
     * Without access to the target type: none.
     *
     * @param array<string, mixed> $config the field's config ("relation": to, labelField, orderBy)
     * @return array<string, string>|null
     */
    public function choices(array $config): ?array
    {
        $cfg = $this->relationConfig($config);
        if ($this->isLarge($cfg)) {
            return null;
        }
        if ($this->can !== null && $cfg['to'] !== '' && !($this->can)($cfg['to'] . '.view')) {
            return [];
        }

        return $this->options($cfg);
    }

    /**
     * One page of the search endpoint: loose search over the target (a blank term lists all), in the
     * picker's order, then by id. Labels follow the picker's rule (labelField, else the first string
     * field that is not hidden).
     *
     * $labelField, $orderBy and $metaField come from the request, so they are only used when a relation
     * field pointing at $to declares that very labelField / orderBy / metaField, and never when the field
     * is hidden; with a meta field each item carries "meta" (0.75);
     * anything else is ignored (no hidden value can be read or sorted on through here).
     *
     * @return array{items: list<array{id: string, label: string, meta?: ?string}>, more: bool}
     */
    public function page(string $to, ?string $labelField, ?string $orderBy, string $term, int $page, ?string $metaField = null): array
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
            'metaField' => $metaField !== null && in_array($metaField, $declared['metaField'], true) ? $metaField : null,
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
                $items[] = ['id' => (string) $id, 'label' => $this->labelFor($record, $cfg)]
                    + ($cfg['metaField'] !== null ? ['meta' => $this->metaFor($record, $cfg)] : []);
            }
        }

        return ['items' => $items, 'more' => $result->hasMore];
    }

    /**
     * The labelField / orderBy values relation fields pointing at $to declare, limited to existing
     * fields of $to that are not hidden.
     *
     * @return array{labelField: list<string>, orderBy: list<string>, metaField: list<string>}
     */
    private function declaredFor(string $to): array
    {
        $target = $this->registry->get($to);
        $out = ['labelField' => [], 'orderBy' => [], 'metaField' => []];
        foreach ($this->dm->relationsTo($to) as $relation) {
            $config = $this->registry->get($relation->type)->fields[$relation->field]->config['relation'] ?? [];
            foreach (['labelField', 'orderBy', 'metaField'] as $key) {
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
        return ['fields.js'];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{to: string, multiple: bool, labelField: ?string, orderBy: ?string, metaField: ?string}
     */
    private function relationConfig(array $config): array
    {
        $r = is_array($config['relation'] ?? null) ? $config['relation'] : [];
        return [
            'to' => (string) ($r['to'] ?? ''),
            'multiple' => (bool) ($r['multiple'] ?? false),
            'labelField' => isset($r['labelField']) ? (string) $r['labelField'] : null,
            'orderBy' => isset($r['orderBy']) && $r['orderBy'] !== '' ? (string) $r['orderBy'] : null,
            'metaField' => isset($r['metaField']) && is_string($r['metaField']) && $r['metaField'] !== '' ? $r['metaField'] : null,
        ];
    }

    /**
     * @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string, metaField: ?string} $cfg
     * @return array<string, string> id => label
     */
    private function options(array $cfg): array
    {
        return array_map(static fn (array $entry): string => $entry['label'], $this->entries($cfg));
    }

    /**
     * Every target record as id => {label, meta} (the relation's "metaField", 0.75), in the picker's order.
     *
     * @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string, metaField: ?string} $cfg
     * @return array<string, array{label: string, meta: ?string}>
     */
    private function entries(array $cfg): array
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
            $out[(string) $id] = ['label' => $this->labelFor($record, $cfg), 'meta' => $this->metaFor($record, $cfg)];
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

    /**
     * The linked records of a large target as id => {label, meta} (one query; an id that no longer
     * resolves is left out).
     *
     * @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string, metaField: ?string} $cfg
     * @param string[] $ids
     * @return array<string, array{label: string, meta: ?string}>
     */
    private function entriesFor(array $cfg, array $ids): array
    {
        if ($ids === [] || $cfg['to'] === '' || !$this->registry->has($cfg['to'])) {
            return [];
        }
        $idField = $this->registry->get($cfg['to'])->idField;
        $out = [];
        foreach ($this->dm->queryType($cfg['to'])->whereAny([['field' => $idField, 'operator' => 'IN', 'value' => $ids]])->get()->items() as $record) {
            $id = $record->getId();
            if ($id !== null) {
                $out[(string) $id] = ['label' => $this->labelFor($record, $cfg), 'meta' => $this->metaFor($record, $cfg)];
            }
        }

        return $out;
    }

    /**
     * The meta line under a row's label: the record's "metaField" in the content language (as the
     * label), null when unset, empty, hidden or not text.
     *
     * @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string, metaField: ?string} $cfg
     */
    private function metaFor(DynamicRecord $record, array $cfg): ?string
    {
        $def = $cfg['metaField'] !== null && $this->registry->has($cfg['to']) ? ($this->registry->get($cfg['to'])->fields[$cfg['metaField']] ?? null) : null;
        if ($def === null || self::hidden($def)) {
            return null;
        }
        $value = $record->localized($this->contentLocale?->get() ?? $record->getType()->locales->default)->get($cfg['metaField']);

        return is_string($value) && $value !== '' ? $value : (is_int($value) || is_float($value) ? (string) $value : null);
    }

    /** @param array{to: string, multiple: bool, labelField: ?string, orderBy: ?string, metaField: ?string} $cfg */
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
