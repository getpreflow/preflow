<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\View\TemplateEngineInterface;

/**
 * Renders a record's fields to a safe per-field HTML map and renders a record
 * through its per-type frontend template (with a default fallback). Shared by
 * the page frontend and the matrix field.
 */
final class RecordRenderer
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly TemplateEngineInterface $engine,
    ) {}

    /**
     * @return array<string, string> field name => safe frontend HTML
     */
    public function renderedMap(DynamicRecord $record): array
    {
        $typeDef = $record->getType();
        $map = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            $map[$name] = $fieldType->renderFrontend(
                $fieldType->fromStorage($record->get($name)),
                $fieldDef->config,
            );
        }
        return $map;
    }

    /**
     * Resolve and render the record's per-type frontend template. With a view,
     * a per-view variant (@folio/frontend/types/{type}_{view}.twig) is tried
     * first, then the per-type template, then the package default. Userland may
     * override any of these via the @folio namespace.
     */
    public function renderTypeTemplate(DynamicRecord $record, string $view = ''): string
    {
        $type = $record->getType()->key;

        // Validate view: must match ^[a-z0-9_-]+$, otherwise treat as empty
        if ($view !== '' && preg_match('/^[a-z0-9_-]+$/', $view) !== 1) {
            $view = '';
        }

        $candidates = [];
        if ($view !== '') {
            $candidates[] = '@folio/frontend/types/' . $type . '_' . $view . '.twig';
        }
        $candidates[] = '@folio/frontend/types/' . $type . '.twig';

        $template = '@folio/frontend/types/_default.twig';
        foreach ($candidates as $candidate) {
            if ($this->engine->exists($candidate)) {
                $template = $candidate;
                break;
            }
        }

        return $this->engine->render($template, [
            'record' => $record->toArray(),
            'rendered' => $this->renderedMap($record),
            'type' => $type,
            'view' => $view,
        ]);
    }
}
