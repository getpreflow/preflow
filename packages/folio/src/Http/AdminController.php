<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Psr\Http\Message\UploadedFileInterface;
use Preflow\Validation\ValidationException;
use Preflow\Folio\Override\ActionResolver;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly TemplateEngineInterface $engine,
        private readonly ActionResolver $overrides,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly string $prefix,
        private readonly RecordLabeler $labeler,
        private readonly string $frontendType,
    ) {}

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

        $rows = [];
        foreach ($this->dm->queryType($type)->get()->items() as $record) {
            $rows[] = $record->toArray() + ['id' => $record->getId()];
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        return $this->html($this->engine->render('@folio/admin/list.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'rows' => $rows,
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

        $data = $this->collectFieldData($typeDef, $request, []);
        $data[$typeDef->idField] = bin2hex(random_bytes(16));

        try {
            $this->dm->saveType(DynamicRecord::fromArray($typeDef, $data));
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

        $data = $this->collectFieldData($typeDef, $request, $current->toArray());
        $data[$typeDef->idField] = $id;

        try {
            $this->dm->saveType(DynamicRecord::fromArray($typeDef, $data));
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
            'previewable' => $type === $this->frontendType,
            'preview_url' => $action . '/preview',
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    /**
     * Build the storage payload, routing upload fields through their uploaded
     * files + kept existing paths, and all others through normalize + toStorage.
     *
     * @param array<string, mixed> $existing current stored values (for update)
     * @return array<string, mixed>
     */
    private function collectFieldData(TypeDefinition $typeDef, ServerRequestInterface $request, array $existing): array
    {
        $submitted = (array) $request->getParsedBody();
        $uploads = $request->getUploadedFiles();
        $data = [];

        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);

            if ($fieldType instanceof HandlesUpload) {
                $files = $this->uploadedFilesFor($uploads[$name] ?? null);
                $removed = array_values(array_filter(
                    (array) ($submitted[$name . '_remove'] ?? []),
                    static fn ($v) => is_string($v),
                ));
                $existingList = $this->pathList($fieldType->fromStorage($existing[$name] ?? null));
                $kept = array_values(array_diff($existingList, $removed));
                $domain = $fieldType->storeUploads($files, $kept, $fieldDef->config);
                $data[$name] = $fieldType->toStorage($domain);
                continue;
            }

            $data[$name] = $fieldType->toStorage(
                $fieldType->normalizeInput($submitted[$name] ?? null, $fieldDef->config),
            );
        }

        return $data;
    }

    /** @return \Psr\Http\Message\UploadedFileInterface[] */
    private function uploadedFilesFor(mixed $entry): array
    {
        if ($entry instanceof UploadedFileInterface) {
            return [$entry];
        }
        if (is_array($entry)) {
            return array_values(array_filter($entry, static fn ($f) => $f instanceof UploadedFileInterface));
        }
        return [];
    }

    /** @return string[] */
    private function pathList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        return [];
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
