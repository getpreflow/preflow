<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldVisibility;
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
        private readonly ?ContentLocale $contentLocale = null,
    ) {}

    /**
     * A field its show_when hides is null (since 0.91.0; FieldVisibility::visibleValues()).
     *
     * @return array<string, ?string> field name => safe frontend HTML; null for a hidden field
     */
    public function renderedMap(DynamicRecord $record, ?string $locale = null): array
    {
        $typeDef = $record->getType();
        $localized = $record->localized($locale ?? $this->locale($record));
        // A type without show_when hides nothing: no values to read for it.
        $hidden = FieldVisibility::rulesOf($typeDef->fields) === [] ? [] : array_flip(FieldVisibility::hiddenFieldsOf($typeDef, $record->toArray()));
        $map = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            if (isset($hidden[$name])) {
                $map[$name] = null;
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            $map[$name] = $fieldType->renderFrontend(
                $fieldType->fromStorage($localized->get($name)),
                $fieldDef->config,
            );
        }
        return $map;
    }

    /**
     * The record's values in $locale as a template reads them: a field its show_when hides is
     * null (FieldVisibility::visibleValues(); the stored value stays in the record).
     *
     * @return array<string, mixed>
     * @since 0.91.0
     */
    public function values(DynamicRecord $record, string $locale): array
    {
        return FieldVisibility::visibleValues($record->getType(), $record->localized($locale)->toArray());
    }

    /** The request's content locale, or the record's default locale. */
    private function locale(DynamicRecord $record): string
    {
        return $this->contentLocale?->get() ?? $record->getType()->locales->default;
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
            'record' => $this->values($record, $this->locale($record)),
            'rendered' => $this->renderedMap($record),
            'type' => $type,
            'view' => $view,
        ]);
    }
}
