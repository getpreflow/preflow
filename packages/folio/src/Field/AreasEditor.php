<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\Placement;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\FolioText;
use Preflow\View\TemplateEngineInterface;

/**
 * The layout editor (0.92), one renderer for a page's areas and the areas field: the layout picker
 * (or "Layout: Home (fixed)" for a pinned page), the notice for a layout that is no longer
 * available, each area of the layout as a block list in the layout's order, and the panel "Not
 * shown in this layout" with the blocks of the areas the layout lacks (Placement::unplaced()), each
 * movable to an area of the layout that accepts it, or removable. Nothing is decided here: the
 * placement is the core's, and so are the refusals it shows ($errors).
 *
 * The picker and the areas post under $layoutName and $areasName ("_layout", "_areas[<area>]" for a
 * page; "<name>[layout]", "<name>[areas][<area>]" for an areas field). A switch (the picker) and a
 * move (a panel card's Move) are POST {admin}/_areas, which answers the areas from the posted form
 * (BlockEditorController::areas(); nothing is stored), swapped in by htmx and announced as an edit.
 *
 * A page's record form shows the picker above the type's fields and the areas after them (render()
 * with $part PICKER and AREAS); the areas field shows both together (WHOLE).
 *
 * @since 0.92.0
 */
final class AreasEditor
{
    /** The picker and the areas together (the areas field). */
    public const string WHOLE = 'whole';
    /** The picker, the fixed line and the notice (a page form's first control). */
    public const string PICKER = 'picker';
    /** The areas and the panel (a page form, after the type's fields). */
    public const string AREAS = 'areas';
    /** The areas and the panel as the switch answers them, with the notice replaced (htmx out of band). */
    public const string SWAP = 'swap';

    public function __construct(
        private readonly TemplateEngineInterface $engine,
        private readonly BlocksFieldType $blocks,
        private readonly BlockRegistry $registry,
        private readonly string $adminPrefix = '/folio',
    ) {}

    /**
     * @param list<AreaLayout>                  $choices    the layouts the editor may pick, in order (none for a pinned page);
     *        with $missingKey the picker starts on that key ("Standard (until you choose a layout)"), so a save as shown keeps it
     * @param AreaLayout|null                   $current    the layout the areas show (the picker's choice); null: none exists
     * @param string|null                       $fixedLabel a pinned page's layout label ("Layout: Home (fixed)", no picker)
     * @param string|null                       $missingKey the stored (or pinned) key that is no longer available (the notice)
     * @param array<string, list<string>|string> $errors    the form's errors; those keyed under the dotted $layoutName or
     *        $areasName are shown: "_layout", "_areas", "_areas.<area>", "_areas.<area>.<uid>.<field>…"
     * @param array{type: string, id: ?string}|null $record
     * @param string|null                       $set        the areas field's layout set (the endpoint's "set"); null for a page
     */
    public function render(string $layoutName, string $areasName, array $choices, ?AreaLayout $current, Placement $placement, ?string $fixedLabel, ?string $missingKey, Locales $locales, string $locale, int $depth, array $errors, ?array $record, string $part = self::WHOLE, ?string $set = null): string
    {
        $id = 'folio-areas-' . hash('xxh3', $areasName);
        $url = $this->adminPrefix . '/_areas?' . http_build_query(array_filter([
            'name' => $areasName,
            'type' => $record['type'] ?? '',
            'id' => $record['id'] ?? '',
            'locale' => $locale,
            'depth' => (string) $depth,
            'set' => $set ?? '',
        ], static fn (string $value): bool => $value !== ''), '', '&', PHP_QUERY_RFC3986);
        $options = array_map(static fn (AreaLayout $layout): array => [
            'key' => $layout->key,
            'label' => FolioText::label($layout->label),
            'description' => $layout->description !== null ? FolioText::label($layout->description) : null,
        ], $choices);
        $selected = $current?->key;
        if ($missingKey !== null && $fixedLabel === null && $current !== null) {
            // The stored key that is no longer available stays the picker's value, shown as the layout the
            // page shows meanwhile: saving as shown writes nothing new (an unchanged key passes the core's
            // check), and only choosing a layout, the fallback too, changes it.
            array_unshift($options, [
                'key' => $missingKey,
                'label' => FolioText::get('folio.page_layouts.fallback_choice', ['label' => FolioText::label($current->label)]),
                'description' => null,
            ]);
            $selected = $missingKey;
        }
        $layoutKey = self::dotted($layoutName);
        $areasKey = self::dotted($areasName);
        $context = [
            'part' => $part,
            'id' => $id,
            'url' => $url,
            'layout_name' => $layoutName,
            'choices' => $options,
            'current' => $selected,
            'fixed' => $fixedLabel,
            'notice' => $missingKey === null ? null : FolioText::get('folio.page_layouts.unavailable', [
                'key' => $missingKey,
                'label' => $current !== null ? FolioText::label($current->label) : $missingKey,
            ]),
            'layout_errors' => self::messages($errors[$layoutKey] ?? []),
            // A page's form says that it posts its layout section (DraftRecordFactory); an areas field always does.
            'marker' => $areasName === PageLayouts::AREAS ? '_areas_submitted' : null,
        ];
        if ($part === self::PICKER) {
            return $this->engine->render('@folio/admin/areas/section.twig', $context);
        }

        $areas = [];
        foreach ($placement->placed() as $name => $blocks) {
            $area = $current?->areas[$name] ?? new AreaDefinition($name, $name);
            [$own, $nested] = self::areaErrors($errors, $areasKey . '.' . $name);
            $areas[] = $this->blocks->renderList(
                $areasName . '[' . $name . ']',
                FolioText::areaLabel($area),
                $area->help !== null ? FolioText::label($area->help) : null,
                $blocks,
                $this->configOf($area),
                $own,
                $nested,
                $locales,
                $locale,
                $depth,
                $record,
            );
        }

        $hidden = [];
        $move = [
            'url' => $url,
            'target' => '#' . $id,
            'targets' => function (string $blockKey) use ($current): array {
                $targets = [];
                foreach ($current->areas ?? [] as $name => $area) {
                    if ($area->accepts($blockKey)) {
                        $targets[] = ['area' => (string) $name, 'label' => FolioText::areaLabel($area)];
                    }
                }

                return $targets;
            },
        ];
        foreach ($placement->unplaced() as $name => $blocks) {
            [$own, $nested] = self::areaErrors($errors, $areasKey . '.' . $name);
            $hidden[] = $this->blocks->renderList(
                $areasName . '[' . $name . ']',
                $this->labelOf($name, $choices),
                null,
                $blocks,
                [],
                $own,
                $nested,
                $locales,
                $locale,
                $depth,
                $record,
                picker: false,
                move: $move,
            );
        }

        // An area the layout lacks that holds nothing (a move emptied it) still posts, empty: else it
        // would keep the blocks it had when stored.
        $emptied = array_keys(array_diff_key($placement->areas(), $placement->placed(), $placement->unplaced()));

        return $this->engine->render('@folio/admin/areas/section.twig', $context + [
            'areas_errors' => self::messages($errors[$areasKey] ?? []),
            'areas' => $areas,
            'hidden' => $hidden,
            'emptied' => array_map(static fn (int|string $name): string => $areasName . '[' . $name . '][_order][]', $emptied),
        ]);
    }

    /**
     * A page's layout editor from its values (the stored record, or the form as posted): the layout
     * they resolve to (PageLayouts::resolveValues()), its pin, the notice for a key that is no longer
     * available.
     *
     * @param array<string, mixed>               $values
     * @param array<string, list<string>|string> $errors
     * @param array{type: string, id: ?string}|null $record
     */
    public function renderPage(PageLayouts $layouts, TypeDefinition $def, array $values, string $locale, array $errors, ?array $record, string $part): string
    {
        $type = $layouts->forType($def->key);
        $resolved = $layouts->resolveValues($def, $values);
        if ($type === null || $resolved === null) {
            return '';
        }
        $pin = $resolved->pinned ? $type->pinFor($resolved->pinnedSlug) : null;
        $missing = $resolved->fallback ? ($pin ?? $resolved->storedKey) : null;
        $fixed = null;
        if ($pin !== null) {
            $fixed = FolioText::get('folio.page_layouts.fixed', ['label' => $resolved->fallback || $resolved->layout === null ? $pin : FolioText::label($resolved->layout->label)]);
        }

        return $this->render(
            PageLayouts::LAYOUT,
            PageLayouts::AREAS,
            $pin !== null ? [] : $layouts->selectable($def->key),
            $resolved->layout,
            $resolved->placement,
            $fixed,
            $missing,
            $def->locales,
            $locale,
            0,
            $errors,
            $record,
            $part,
        );
    }

    /**
     * The area as a blocks field: "allowed" (with "except" taken out of every block the registry
     * has), "min", "max".
     *
     * @return array<string, mixed>
     */
    private function configOf(AreaDefinition $area): array
    {
        $config = $area->blocksField()->config;
        if ($area->except !== []) {
            $config['allowed'] = array_values(array_filter(
                $area->allowed !== [] ? $area->allowed : array_map('strval', array_keys($this->registry->all())),
                static fn (string $key): bool => !in_array($key, $area->except, true),
            ));
        }

        return $config;
    }

    /**
     * An area the layout lacks, by the label another of the choices gives it, else its humanized name.
     *
     * @param list<AreaLayout> $choices
     */
    private function labelOf(string $name, array $choices): string
    {
        foreach ($choices as $layout) {
            if (isset($layout->areas[$name])) {
                return FolioText::areaLabel($layout->areas[$name]);
            }
        }

        return ucfirst(str_replace('_', ' ', $name));
    }

    /**
     * An area's own errors (its key, and keys below it that name no block: "<area>.<i>._uid") and
     * its blocks' errors ("<uid>.<field>…", relative to the area).
     *
     * @param array<string, list<string>|string> $errors
     * @return array{list<string>, array<string, list<string>>}
     */
    private static function areaErrors(array $errors, string $at): array
    {
        $own = self::messages($errors[$at] ?? []);
        $nested = [];
        foreach ($errors as $key => $messages) {
            $key = (string) $key;
            if (!str_starts_with($key, $at . '.')) {
                continue;
            }
            $rest = substr($key, strlen($at) + 1);
            if (preg_match('/^[a-f0-9]{16}\./', $rest) === 1) {
                $nested[$rest] = self::messages($messages);
            } else {
                $own = [...$own, ...self::messages($messages)];
            }
        }

        return [$own, $nested];
    }

    /**
     * @param list<string>|string $messages
     * @return list<string>
     */
    private static function messages(array|string $messages): array
    {
        return is_array($messages) ? array_map('strval', $messages) : [$messages];
    }

    /** "a[b][c]" -> "a.b.c", as the core keys errors. */
    private static function dotted(string $name): string
    {
        return str_replace(['][', '[', ']'], ['.', '.', ''], $name);
    }
}
