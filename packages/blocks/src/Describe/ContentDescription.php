<?php

declare(strict_types=1);

namespace Preflow\Blocks\Describe;

use Preflow\Blocks\BlockDefinition;
use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Values\AreasFieldValue;
use Preflow\Data\Describe\DescriptionContributor;
use Preflow\Data\FieldKinds;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Values\DescribesShapes;

/**
 * The site's blocks, page layouts, area layout sets and the types' layouts as one JSON-serializable
 * description, for an OpenAPI or GraphQL schema builder (or a client that builds its editor):
 *
 *     {"version": 1,
 *      "blocks": {"text": {"label": "Text", "group": "Blocks", "description": null, "icon": null, "family": null, "renders": true,
 *                          "fields": {"body": {"type": "richtext", "label": null, "help": null, "translatable": true, "required": true, "list": false, "config": {}}}}},
 *      "layouts": {"standard": {"label": "Standard", "description": null, "areas": {"main": {"label": "Main", "label_params": {}, "allowed": [], "except": [], "min": null, "max": null}}}},
 *      "area_layout_sets": {"columns": {"fallback": "full", "layouts": {"full": {…, "areas": {"c1": {"label": "block-library.columns.column", "label_params": {"n": 1}, …, "width": 1}}}}}},
 *      "types": {"page": {"layouts": {"allowed": ["standard"], "default": "standard", "pinned": {}, "slug_field": "slug",
 *                                     "values": {"_layout": "layout_key", "_areas": "area_map"}}}}}
 *
 * Declared data only: no PHP class names, no template paths, and labels as declared (a key-shaped
 * label stays the key, with an area's "label_params" for its :name placeholders since 0.92; translating
 * it is the API's choice, through its translator), so every site
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
 * A field of the type "areas" (since 0.92: the columns block's columns) names its set in
 * "config.layouts" (a key of "area_layout_sets") and its value shape in "value":
 *
 * - "areas_value": an object {"layout": a key of that set's "layouts" or null, "areas": an
 *   "area_map" whose names are those of the set's layouts' areas}.
 *
 * An empty map is an object ({}), never a list. "version" grows when a key changes meaning or goes;
 * a new key may be added within a version.
 *
 * Since 0.93 it is also the blocks package's part of the one description of every type
 * (Preflow\Data\Describe\Description, a DescriptionContributor): sections() gives "blocks",
 * "layouts" and "area_layout_sets", forType() a type's "layouts", shapes() the shapes they name.
 * toArray() keeps its 0.92 output.
 *
 * @since 0.92.0
 */
final readonly class ContentDescription implements DescriptionContributor, DescribesShapes
{
    public const int VERSION = 1;

    /**
     * The shapes of a type's stored layout keys, as the description's "values" names them.
     *
     * @since 0.93.0
     */
    public const array SHAPES = [
        'layout_key' => 'A key of "layouts" (a string), or null for the type\'s default.',
        'area_map' => 'An object of area name => list of blocks, each block an object with "_uid", "_block" (a key of "blocks") and its fields (a translatable one a map of locale => value).',
    ];

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
        $types = [];
        foreach ($this->types?->keys() ?? [] as $type) {
            $layouts = $this->typeLayouts($type);
            if ($layouts !== null) {
                $types[$type] = ['layouts' => $layouts];
            }
        }

        return [
            'version' => self::VERSION,
            ...$this->sections(),
            'types' => self::map($types),
        ];
    }

    /**
     * Its part of the one description (Preflow\Data\Describe\Description, since 0.93): the
     * sections "blocks", "layouts" and "area_layout_sets", as toArray() gives them.
     *
     * @return array{blocks: array<string, mixed>|\stdClass, layouts: array<string, mixed>|\stdClass, area_layout_sets: array<string, mixed>|\stdClass}
     * @since 0.93.0
     */
    public function sections(): array
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

        return [
            'blocks' => self::map($blocks),
            'layouts' => self::map($layouts),
            'area_layout_sets' => self::map($sets),
        ];
    }

    /**
     * A type's "layouts" in the one description, as toArray() gives it under "types"; nothing for
     * a type without layouts.
     *
     * @return array<string, mixed>
     * @since 0.93.0
     */
    public function forType(TypeDefinition $def): array
    {
        $layouts = $this->typeLayouts($def->key);

        return $layouts === null ? [] : ['layouts' => $layouts];
    }

    /**
     * The shapes its sections and type entries name: "layout_key" and "area_map" when a type has
     * layouts, "areas_value" when a block has an areas field.
     *
     * @return array<string, string>
     * @since 0.93.0
     */
    public function shapes(): array
    {
        $shapes = [];
        foreach ($this->types?->keys() ?? [] as $type) {
            if ($this->pageLayouts?->forType($type) !== null) {
                $shapes = self::SHAPES;
                break;
            }
        }
        foreach ($this->blocks->all() as $block) {
            foreach ($block->fields as $field) {
                if ($field->type === BlockLists::AREAS) {
                    return $shapes + AreasFieldValue::SHAPES;
                }
            }
        }

        return $shapes;
    }

    /** @return array<string, mixed>|null */
    private function typeLayouts(string $type): ?array
    {
        $config = $this->pageLayouts?->forType($type);
        if ($config === null) {
            return null;
        }

        return [
            'allowed' => $config->allowed,
            'default' => $config->default,
            'pinned' => self::map($config->pinned),
            'slug_field' => $config->slugField,
            'values' => [PageLayouts::LAYOUT => 'layout_key', PageLayouts::AREAS => 'area_map'],
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
        $out = [
            'type' => $field->type,
            'label' => $field->label,
            'help' => $field->help,
            'translatable' => $field->translatable,
            'required' => in_array('required', $field->validate, true),
            'list' => $this->kinds->holdsList($field),
            'config' => self::map($field->config),
        ];
        if ($field->type === BlockLists::AREAS) {
            // Its layouts are those of the set "config.layouts" names (under "area_layout_sets").
            $out['value'] = 'areas_value';
        }

        return $out;
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
        $out = ['label' => $area->label, 'label_params' => self::map($area->labelParams), 'allowed' => $area->allowed, 'except' => $area->except, 'min' => $area->min, 'max' => $area->max];
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
