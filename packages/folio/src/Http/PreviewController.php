<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders a draft (in-progress, unsaved) record through the real frontend
 * template for live preview. Side-effect-free: never writes storage, never
 * persists uploads, never calls saveType (so no validation), and ignores the
 * published-status gate. Enabled only for the frontend type.
 */
final class PreviewController
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly RecordRenderer $records,
        private readonly TemplateEngineInterface $engine,
        private readonly string $frontendType,
    ) {}

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $type !== $this->frontendType) {
            return new Response(404, [], 'Not found');
        }

        $typeDef = $this->registry->get($type);
        $id = (string) $request->getAttribute('id', '');
        $draft = $this->draftRecord($typeDef, $request, $id);

        $html = $this->engine->render('@folio/frontend/page.twig', [
            'record' => $draft->toArray(),
            'rendered' => $this->records->renderedMap($draft),
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    /**
     * Build an in-memory record from the submitted form values WITHOUT saving.
     * Upload fields reuse storeUploads with an EMPTY uploaded list: that writes
     * no files (verified in AssetFieldType::storeUploads — the loop is skipped)
     * yet returns the field's correct domain shape for kept-minus-removed paths.
     */
    private function draftRecord(TypeDefinition $typeDef, ServerRequestInterface $request, string $id): DynamicRecord
    {
        $submitted = (array) $request->getParsedBody();
        $existing = $id !== '' ? ($this->dm->findType($typeDef->key, $id)?->toArray() ?? []) : [];
        $data = [];

        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);

            if ($fieldType instanceof HandlesUpload) {
                $removed = array_values(array_filter(
                    (array) ($submitted[$name . '_remove'] ?? []),
                    static fn ($v) => is_string($v),
                ));
                $existingList = $this->pathList($fieldType->fromStorage($existing[$name] ?? null));
                $kept = array_values(array_diff($existingList, $removed));
                $data[$name] = $fieldType->toStorage($fieldType->storeUploads([], $kept, $fieldDef->config));
                continue;
            }

            $data[$name] = $fieldType->toStorage(
                $fieldType->normalizeInput($submitted[$name] ?? null, $fieldDef->config),
            );
        }

        $data[$typeDef->idField] = $id;

        return DynamicRecord::fromArray($typeDef, $data);
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
}
