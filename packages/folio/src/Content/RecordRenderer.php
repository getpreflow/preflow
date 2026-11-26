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
 * the page frontend and the matrix field. Finds a page's layout (layoutFor(), 0.92).
 */
final class RecordRenderer
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly TemplateEngineInterface $engine,
        private readonly ?ContentLocale $contentLocale = null,
        private readonly ?\Preflow\Blocks\BlockRegistry $blocks = null,
        /** The types' page layouts (0.92); without, no record has a layout. */
        private readonly ?\Preflow\Blocks\Layouts\PageLayouts $layouts = null,
    ) {}

    /**
     * The layout the record renders with (PageLayouts::resolve(): its pin, its "_layout", the
     * type's default); null for a type without "layouts". A draft resolves the same way, from the
     * values the form posted. Its context() is the page template's "layout" and "areas".
     *
     * @since 0.92.0
     */
    public function layoutFor(DynamicRecord $record): ?\Preflow\Blocks\Layouts\ResolvedLayout
    {
        return $this->layouts?->resolve($record);
    }

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
     * null (FieldVisibility::visibleValues(); the stored value stays in the record), also inside its
     * blocks fields (BlockRegistry::visibleBlocksIn()).
     *
     * @return array<string, mixed>
     * @since 0.91.0
     */
    public function values(DynamicRecord $record, string $locale): array
    {
        $values = FieldVisibility::visibleValues($record->getType(), $record->localized($locale)->toArray());

        return $this->blocks?->visibleBlocksIn($values, $record->getType()->fields) ?? $values;
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
