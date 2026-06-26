<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;

/**
 * Ordered, polymorphic reference list. Stores [{_type,id},...] referencing
 * standalone records of allowed (matrixable) content types. Edited via admin.js
 * (add pick-existing / remove / reorder); rendered on the frontend through each
 * reference's per-type template.
 */
final class MatrixFieldType implements FieldType
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly RecordRenderer $records,
        private readonly RecordLabeler $labeler,
        private readonly string $prefix,
    ) {}

    public function key(): string
    {
        return 'matrix';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $name = $ctx->name;
        $allowed = $this->allowedTypes($ctx->config);
        $refs = $this->toRefs($ctx->value);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $name));

        // Options blob for the JS picker: types + their records (id,label) + declared views.
        $options = ['prefix' => $this->prefix, 'types' => [], 'records' => [], 'views' => []];
        foreach ($allowed as $key) {
            $options['types'][] = ['key' => $key, 'label' => $this->typeLabel($key)];
            $recs = [];
            foreach ($this->dm->queryType($key)->get()->items() as $record) {
                $id = $record->getId();
                if ($id === null) {
                    continue;
                }
                $recs[] = ['id' => (string) $id, 'label' => $this->labeler->label($record)];
            }
            $options['records'][$key] = $recs;
            $options['views'][$key] = $this->viewsFor($key);
        }
        $optionsJson = json_encode($options, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);

        $html = '<div class="form-group folio-matrix-field">' . "\n";
        $html .= '  <label>' . $e($label) . '</label>' . "\n";
        $html .= '  <div class="folio-matrix" data-folio-matrix data-field="' . $e($name) . '" data-next-index="' . count($refs) . '">' . "\n";
        $html .= '    <script type="application/json" data-matrix-options>' . $optionsJson . '</script>' . "\n";
        $html .= '    <div class="folio-matrix-rows" data-matrix-rows>' . "\n";
        foreach (array_values($refs) as $i => $ref) {
            $html .= $this->rowHtml(
                $name,
                $i,
                $ref['_type'],
                $ref['id'],
                $this->refLabel($ref),
                $this->viewsFor($ref['_type']),
                $ref['view'] ?? '',
            );
        }
        $html .= '    </div>' . "\n";
        $html .= '    <div class="folio-matrix-add">' . "\n";
        $html .= '      <select data-matrix-type>';
        foreach ($allowed as $key) {
            $html .= '<option value="' . $e($key) . '">' . $e($this->typeLabel($key)) . '</option>';
        }
        $html .= '</select>' . "\n";
        $html .= '      <select data-matrix-record></select>' . "\n";
        $html .= '      <button type="button" class="btn btn-secondary" data-matrix-add>Add</button>' . "\n";
        $html .= '      <button type="button" class="btn btn-secondary" data-matrix-create>New</button>' . "\n";
        $html .= '    </div>' . "\n";
        $html .= '  </div>' . "\n";
        $html .= '</div>';

        return $html;
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if (!is_array($raw)) {
            return [];
        }
        $allowed = $this->allowedTypes($config);
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = (string) ($entry['_type'] ?? '');
            $id = (string) ($entry['id'] ?? '');
            if ($type === '' || $id === '' || !in_array($type, $allowed, true)) {
                continue;
            }
            $ref = ['_type' => $type, 'id' => $id];
            $view = (string) ($entry['view'] ?? '');
            if ($view !== '' && in_array($view, $this->viewsFor($type), true)) {
                $ref['view'] = $view;
            }
            $out[] = $ref;
        }
        return $out;
    }

    public function toStorage(mixed $value): mixed
    {
        return json_encode($this->toRefs($value), JSON_UNESCAPED_SLASHES);
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->toRefs($value);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $out = '';
        foreach ($this->toRefs($value) as $ref) {
            if (!$this->registry->has($ref['_type'])) {
                continue;
            }
            $record = $this->dm->findType($ref['_type'], $ref['id']);
            if ($record === null) {
                continue;
            }
            $out .= $this->records->renderTypeTemplate($record, $ref['view'] ?? '');
        }
        return $out;
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return ['admin.js'];
    }

    /**
     * Effective allowed types = (config.allowed or all type keys) ∩ matrixable.
     *
     * @param array<string, mixed> $config
     * @return string[]
     */
    private function allowedTypes(array $config): array
    {
        $matrix = is_array($config['matrix'] ?? null) ? $config['matrix'] : [];
        $allowed = array_values(array_filter((array) ($matrix['allowed'] ?? []), static fn ($v) => is_string($v)));

        $candidates = $allowed !== []
            ? $allowed
            : array_map(static fn ($listing) => $listing->key, $this->catalog->all());

        $out = [];
        foreach ($candidates as $key) {
            if ($this->registry->has($key) && $this->registry->get($key)->matrixable) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Declared views for a type (model JSON `views`), filtered to non-empty strings.
     *
     * @return string[]
     */
    private function viewsFor(string $type): array
    {
        if (!$this->registry->has($type)) {
            return [];
        }
        return array_values(array_filter(
            $this->registry->get($type)->views,
            static fn ($v) => is_string($v) && $v !== '',
        ));
    }

    private function typeLabel(string $key): string
    {
        foreach ($this->catalog->all() as $listing) {
            if ($listing->key === $key) {
                return $listing->label;
            }
        }
        return ucfirst($key);
    }

    /** @param array{_type:string,id:string,view?:string} $ref */
    private function refLabel(array $ref): string
    {
        if (!$this->registry->has($ref['_type'])) {
            return $ref['id'];
        }
        $record = $this->dm->findType($ref['_type'], $ref['id']);
        return $record === null ? $ref['id'] : $this->labeler->label($record);
    }

    /**
     * @param string[] $views declared views for $type ([] => no selector)
     */
    private function rowHtml(string $field, int $i, string $type, string $id, string $label, array $views, string $view): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $viewSelect = '';
        if ($views !== []) {
            $viewSelect = '<select name="' . $e($field) . '[' . $i . '][view]" data-matrix-view>'
                . '<option value="">Default</option>';
            foreach ($views as $v) {
                $viewSelect .= '<option value="' . $e($v) . '"' . ($v === $view ? ' selected' : '') . '>' . $e($v) . '</option>';
            }
            $viewSelect .= '</select>';
        }

        return '      <div class="folio-matrix-row" data-matrix-row>'
            . '<input type="hidden" name="' . $e($field) . '[' . $i . '][_type]" value="' . $e($type) . '">'
            . '<input type="hidden" name="' . $e($field) . '[' . $i . '][id]" value="' . $e($id) . '">'
            . '<span class="folio-matrix-label">' . $e($label) . ' <em>(' . $e($type) . ')</em></span>'
            . $viewSelect
            . '<span class="folio-matrix-controls">'
            . '<button type="button" data-matrix-up>Up</button>'
            . '<button type="button" data-matrix-down>Down</button>'
            . '<button type="button" data-matrix-remove>Remove</button>'
            . '</span></div>' . "\n";
    }

    /**
     * Normalize a stored/raw value to a list of {_type,id} refs, preserving a
     * non-empty view per entry.
     *
     * NOTE: the view carried here is NOT whitelisted against the type's declared
     * views — only normalizeInput does that, on the save path. renderFrontend
     * resolves through this method, so RecordRenderer::renderTypeTemplate's
     * ^[a-z0-9_-]+$ guard is the security backstop on the render path and must
     * stay (do not drop it assuming the data layer already cleaned the view).
     *
     * @return list<array{_type:string,id:string,view?:string}>
     */
    private function toRefs(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = (string) ($entry['_type'] ?? '');
            $id = (string) ($entry['id'] ?? '');
            if ($type === '' || $id === '') {
                continue;
            }
            $ref = ['_type' => $type, 'id' => $id];
            $view = (string) ($entry['view'] ?? '');
            if ($view !== '') {
                $ref['view'] = $view;
            }
            $out[] = $ref;
        }
        return $out;
    }
}
