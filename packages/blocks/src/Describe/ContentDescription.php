<?php

declare(strict_types=1);

namespace Preflow\Blocks\Describe;

use Preflow\Blocks\BlockDefinition;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Data\FieldKinds;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;

/**
 * The site's blocks, page layouts, area layout sets and the types' layouts as one JSON-serializable
 * description, for an OpenAPI or GraphQL schema builder (or a client that builds its editor):
 *
 *     {"version": 1,
 *      "blocks": {"text": {"label": "Text", "group": "Blocks", "description": null, "icon": null, "family": null, "renders": true,
 *                          "fields": {"body": {"type": "richtext", "label": null, "help": null, "translatable": true, "required": true, "list": false, "config": {}}}}},
 *      "layouts": {"standard": {"label": "Standard", "description": null, "areas": {"main": {"label": "Main", "allowed": [], "except": [], "min": null, "max": null}}}},
 *      "area_layout_sets": {"columns": {"fallback": "full", "layouts": {"full": {…, "areas": {"c1": {…, "width": 1}}}}}},
 *      "types": {"page": {"layouts": {"allowed": ["standard"], "default": "standard", "pinned": {}, "slug_field": "slug",
 *                                     "values": {"_layout": "layout_key", "_areas": "area_map"}}}}}
 *
 * Declared data only: no PHP class names, no template paths, and labels as declared (a key-shaped
 * label stays the key; translating it is the API's choice, through its translator), so every site
 * and language gets the same description of the same declarations. "renders": the block has its
 * own component (else its parent's template renders it). A field's "required": its "validate" has
 * "required"; "list": its value is a list of values (FieldKinds::holdsList(): tags, a multiple
 * relation; a blocks field is told by its type); "config": its options as
 * declared ("show_when", "options_from", a blocks field's "allowed", "min", "max" included). An
 * area's "allowed" empty: every block. "types" lists the types with "layouts"; their "values" name
 * the shapes of the stored keys:
 *
 * - "layout_key": a key of "layouts" (a string), or null for the type's default;
 * - "area_map": an object of area name => list of blocks, each block an object with "_uid",
 *   "_block" (a key of "blocks") and its fields (a translatable one a map of locale => value).
 *
 * An empty map is an object ({}), never a list. "version" grows when a key changes meaning or goes;
 * a new key may be added within a version.
 *
 * @since 0.92.0
 */
final readonly class ContentDescription
{
    public const int VERSION = 1;

    public function __construct(
        private BlockRegistry $blocks,
        private LayoutRegistry $layouts,
        private AreaLayoutSets $sets,
        private FieldKinds $kinds,
        private ?PageLayouts $pageLayouts = null,
        private ?TypeRegistry $types = null,
    ) {}

    /** @return array{version: int, blocks: array<string, mixed>|\stdClass, layouts: array<string, mixed>|\stdClass, area_layout_sets: array<string, mixed>|\stdClass, types: array<string, mixed>|\stdClass} */
    public function toArray(): array
    {
        $blocks = [];
        foreach ($this->blocks->all() as $key => $block) {
            $blocks[$key] = $this->block($block);
        }

        $layouts = [];
        foreach ($this->layouts->all() as $key => $layout) {
            $layouts[$key] = self::layout($layout, withWidth: false);
        }

        $sets = [];
        foreach ($this->sets->names() as $name) {
            $set = $this->sets->get($name);
            $inSet = [];
            foreach ($set->all() as $layout) {
                $inSet[$layout->key] = self::layout($layout, withWidth: true);
            }
            $sets[$name] = ['fallback' => $set->fallback, 'layouts' => self::map($inSet)];
        }

        $types = [];
        foreach ($this->types?->keys() ?? [] as $type) {
            $config = $this->pageLayouts?->forType($type);
            if ($config === null) {
                continue;
            }
            $types[$type] = ['layouts' => [
                'allowed' => $config->allowed,
                'default' => $config->default,
                'pinned' => self::map($config->pinned),
                'slug_field' => $config->slugField,
                'values' => [PageLayouts::LAYOUT => 'layout_key', PageLayouts::AREAS => 'area_map'],
            ]];
        }

        return [
            'version' => self::VERSION,
            'blocks' => self::map($blocks),
            'layouts' => self::map($layouts),
            'area_layout_sets' => self::map($sets),
            'types' => self::map($types),
        ];
    }

    /** @return array<string, mixed> */
    private function block(BlockDefinition $block): array
    {
        $fields = [];
        foreach ($block->fields as $name => $field) {
            $fields[$name] = $this->field($field);
        }

        return [
            'label' => $block->label,
            'group' => $block->group,
            'description' => $block->description,
            'icon' => $block->icon,
            'family' => $block->family?->value,
            'renders' => $block->isComponent,
            'fields' => self::map($fields),
        ];
    }

    /** @return array<string, mixed> */
    private function field(TypeFieldDefinition $field): array
    {
        return [
            'type' => $field->type,
            'label' => $field->label,
            'help' => $field->help,
            'translatable' => $field->translatable,
            'required' => in_array('required', $field->validate, true),
            'list' => $this->kinds->holdsList($field),
            'config' => self::map($field->config),
        ];
    }

    /** @return array<string, mixed> */
    private static function layout(AreaLayout $layout, bool $withWidth): array
    {
        $areas = [];
        foreach ($layout->areas as $name => $area) {
            $areas[$name] = self::area($area, $withWidth);
        }

        return ['label' => $layout->label, 'description' => $layout->description, 'areas' => self::map($areas)];
    }

    /** @return array<string, mixed> */
    private static function area(AreaDefinition $area, bool $withWidth): array
    {
        $out = ['label' => $area->label, 'allowed' => $area->allowed, 'except' => $area->except, 'min' => $area->min, 'max' => $area->max];
        if ($withWidth) {
            $out['width'] = $area->width;
        }

        return $out;
    }

    /**
     * A map as JSON keeps it: an empty one is an object.
     *
     * @param array<string, mixed> $map
     * @return array<string, mixed>|\stdClass
     */
    private static function map(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass() : $map;
    }
}
