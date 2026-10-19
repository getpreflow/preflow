<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeRegistry;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Validation\ValidationException;
use Preflow\Folio\Override\ActionResolver;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    private readonly DraftRecordFactory $drafts;

    /** System key holding the manual position of a record in an orderable list. */
    private const ORDER = '_order';

    /** Field types shown as list columns by default. */
    private const LIST_TYPES = ['string', 'number', 'int', 'integer', 'float', 'select', 'boolean', 'date'];

    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly TemplateEngineInterface $engine,
        private readonly ActionResolver $overrides,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly string $prefix,
        private readonly RecordLabeler $labeler,
        private readonly FrontendResolver $frontend,
        ?DraftRecordFactory $drafts = null,
    ) {
        $this->drafts = $drafts ?? new DraftRecordFactory($fieldTypes);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (($override = $this->overrides->resolve('Content', 'Index')) !== null) {
            return $override->handle($request);
        }

        $cards = [];
        foreach ($this->catalog->all() as $listing) {
            $cards[] = [
                'key' => $listing->key,
                'label' => $listing->label,
                'count' => count($this->dm->queryType($listing->key)->get()->items()),
            ];
        }

        return $this->html($this->engine->render('@folio/admin/dashboard.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'cards' => $cards,
        ]));
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $typeDef = $this->registry->get($type);
        $listConfig = $this->catalog->get($type)?->list ?? [];
        $orderable = ($listConfig['orderable'] ?? false) === true;
        $columns = $this->listColumns($typeDef, $listConfig);

        $rows = [];
        foreach ($this->listQuery($type, $typeDef, $listConfig)->get()->items() as $record) {
            $cells = [];
            foreach ($columns as $name => $label) {
                $fieldDef = $typeDef->fields[$name];
                $html = $this->fieldTypes->get($fieldDef->type)->renderFrontend(
                    $this->fieldTypes->get($fieldDef->type)->fromStorage($record->get($name)),
                    $fieldDef->config,
                );
                // Plain text: list cells never render field HTML (e.g. rich text) raw.
                $cells[$name] = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $rows[] = ['id' => $record->getId(), 'cells' => $cells];
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        return $this->html($this->engine->render('@folio/admin/list.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'columns' => $columns,
            'rows' => $rows,
            'orderable' => $orderable,
            'csrf' => $csrf,
        ]));
    }

    public function recordLabel(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], 'Not found');
        }

        $payload = json_encode(
            ['id' => $id, 'label' => $this->labeler->label($record)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], (string) $payload);
    }

    public function createForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
        $drawer = $this->isDrawer($request);
        $action = $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : '');
        $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';

        return $this->form($type, [], $action, 'New ' . $this->labelFor($type), [], $csrf, 200, $layout);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $typeDef = $this->registry->get($type);
        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
        $drawer = $this->isDrawer($request);

        $record = $this->drafts->build($typeDef, $request, [], $typeDef->locales->default);
        $record->set($typeDef->idField, bin2hex(random_bytes(16)));
        if (($this->catalog->get($type)?->list['orderable'] ?? false) === true) {
            $record->set(self::ORDER, $this->maxOrder($type) + 10);
        }
        $data = [$typeDef->idField => $record->getId()];

        try {
            $this->dm->saveType($record, true, $this->rulesFor($typeDef));
        } catch (ValidationException $e) {
            $action = $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : '');
            $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';

            return $this->form(
                $type,
                (array) $request->getParsedBody(),
                $action,
                'New ' . $this->labelFor($type),
                $e->errors(),
                $csrf,
                422,
                $layout,
            );
        }

        if ($drawer) {
            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/drawer_saved.twig', [
                'type' => $type,
                'id' => $data[$typeDef->idField],
            ]));
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }

    public function editForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }
        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], 'Not found');
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        return $this->form(
            $type,
            $record->toArray(),
            $this->prefix . '/' . $type . '/' . $id,
            'Edit ' . $this->labelFor($type),
            [],
            $csrf,
        );
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $current = $this->dm->findType($type, $id);
        if ($current === null) {
            return new Response(404, [], 'Not found');
        }

        $typeDef = $this->registry->get($type);
        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        $record = $this->drafts->build($typeDef, $request, $current->toArray(), $typeDef->locales->default);

        try {
            $this->dm->saveType($record, true, $this->rulesFor($typeDef));
        } catch (ValidationException $e) {
            return $this->form(
                $type,
                (array) $request->getParsedBody(),
                $this->prefix . '/' . $type . '/' . $id,
                'Edit ' . $this->labelFor($type),
                $e->errors(),
                $csrf,
                422,
            );
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }

    /**
     * Move a record one step up or down in an orderable list (POST direction=up|down).
     * Records that have no _order yet are numbered in their current order first.
     */
    public function reorder(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type) || ($this->catalog->get($type)?->list['orderable'] ?? false) !== true) {
            return new Response(404, [], 'Unknown type');
        }

        $typeDef = $this->registry->get($type);
        $records = array_values($this->listQuery($type, $typeDef, ['orderable' => true])->get()->items());

        $missing = false;
        foreach ($records as $record) {
            $missing = $missing || !is_int($record->get(self::ORDER));
        }
        if ($missing) {
            foreach ($records as $i => $record) {
                $record->set(self::ORDER, ($i + 1) * 10);
                $this->dm->saveType($record, validate: false);
            }
        }

        $ids = array_map(static fn ($r) => (string) $r->getId(), $records);
        $index = array_search($id, $ids, true);
        if ($index === false) {
            return new Response(404, [], 'Not found');
        }

        $direction = (string) (((array) $request->getParsedBody())['direction'] ?? '');
        $neighbour = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
        if ($neighbour !== null && isset($records[$neighbour])) {
            $a = $records[$index];
            $b = $records[$neighbour];
            $orderA = $a->get(self::ORDER);
            $a->set(self::ORDER, $b->get(self::ORDER));
            $b->set(self::ORDER, $orderA);
            $this->dm->saveType($a, validate: false);
            $this->dm->saveType($b, validate: false);
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        if ($this->dm->findType($type, $id) === null) {
            return new Response(404, [], 'Not found');
        }

        $this->dm->deleteType($type, $id);

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }

    /** @param array<string, mixed> $values @param array<string, list<string>> $errors */
    private function form(string $type, array $values, string $action, string $heading, array $errors, string $csrf = '', int $status = 200, string $layout = '@folio/admin/_layout.twig'): ResponseInterface
    {
        $typeDef = $this->registry->get($type);
        $fields = [];
        $editorAssets = [];
        $multipart = false;
        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            if ($fieldType instanceof HandlesUpload) {
                $multipart = true;
            }
            $ctx = new FieldContext(
                name: $name,
                label: $fieldDef->label,
                help: $fieldDef->help,
                value: $fieldType->fromStorage($values[$name] ?? null),
                errors: $errors[$name] ?? [],
                config: $fieldDef->config,
                required: in_array('required', $fieldDef->validate, true),
            );
            $fields[] = ['name' => $name, 'html' => $fieldType->renderEditor($ctx)];
            foreach ($fieldType->assets() as $asset) {
                $editorAssets[$asset] = true;
            }
        }

        $html = $this->engine->render('@folio/admin/form.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'heading' => $heading,
            'action' => $action,
            'csrf' => $csrf,
            'fields' => $fields,
            'editor_assets' => array_keys($editorAssets),
            'multipart' => $multipart,
            'layout' => $layout,
            'previewable' => $this->frontend->routeFor($type) !== null,
            'preview_url' => $action . '/preview',
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    /**
     * Per-field rules: the JSON "validate" list plus the field type's own rules
     * (e.g. select's in:…, date's format). Keyed by field, because saveType()
     * merges extra rules per field.
     *
     * @return array<string, list<string>>
     */
    private function rulesFor(TypeDefinition $typeDef): array
    {
        $rules = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            $merged = array_values(array_unique([
                ...$fieldDef->validate,
                ...$this->fieldTypes->get($fieldDef->type)->rules($fieldDef->config),
            ]));
            if ($merged !== []) {
                $rules[$name] = $merged;
            }
        }

        return $rules;
    }

    /**
     * Columns for the admin list: "list.columns" from the type JSON, else the
     * first three plain-valued fields.
     *
     * @param array<string, mixed> $listConfig
     * @return array<string, string> field => header label
     */
    private function listColumns(TypeDefinition $typeDef, array $listConfig): array
    {
        $names = array_values(array_filter(
            (array) ($listConfig['columns'] ?? []),
            static fn ($name) => is_string($name) && isset($typeDef->fields[$name]),
        ));

        if ($names === []) {
            foreach ($typeDef->fields as $name => $fieldDef) {
                if ($name !== $typeDef->idField && in_array($fieldDef->type, self::LIST_TYPES, true)) {
                    $names[] = $name;
                }
                if (count($names) === 3) {
                    break;
                }
            }
        }

        $renderer = new \Preflow\Form\FieldRenderer();
        $columns = [];
        foreach ($names as $name) {
            $columns[$name] = $typeDef->fields[$name]->label ?? $renderer->humanize($name);
        }

        return $columns;
    }

    /** @param array<string, mixed> $listConfig */
    private function listQuery(string $type, TypeDefinition $typeDef, array $listConfig): \Preflow\Data\QueryBuilder
    {
        $query = $this->dm->queryType($type);

        if (($listConfig['orderable'] ?? false) === true) {
            return $query->orderBy(self::ORDER)->orderBy($typeDef->idField);
        }

        $sort = (string) ($listConfig['sort'] ?? '');
        if ($sort !== '') {
            $desc = str_starts_with($sort, '-');
            $query = $query->orderBy(ltrim($sort, '-'), $desc ? SortDirection::Desc : SortDirection::Asc);
        }

        return $query;
    }

    private function maxOrder(string $type): int
    {
        $max = 0;
        foreach ($this->dm->queryType($type)->get()->items() as $record) {
            $max = max($max, (int) $record->get(self::ORDER));
        }

        return $max;
    }

    private function isDrawer(ServerRequestInterface $request): bool
    {
        parse_str($request->getUri()->getQuery(), $q);

        return ($q['_drawer'] ?? null) === '1';
    }

    private function labelFor(string $type): string
    {
        foreach ($this->catalog->all() as $listing) {
            if ($listing->key === $type) {
                return $listing->label;
            }
        }
        return ucfirst($type);
    }

    private function html(string $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
