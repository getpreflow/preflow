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
     * Resolve and render the record's per-type frontend template. Userland may
     * provide @folio/frontend/types/{type}.twig; otherwise the package default
     * is used.
     */
    public function renderTypeTemplate(DynamicRecord $record): string
    {
        $type = $record->getType()->key;
        $template = '@folio/frontend/types/' . $type . '.twig';
        if (!$this->engine->exists($template)) {
            $template = '@folio/frontend/types/_default.twig';
        }

        return $this->engine->render($template, [
            'record' => $record->toArray(),
            'rendered' => $this->renderedMap($record),
            'type' => $type,
        ]);
    }
}
