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
use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Values\AreasFieldValue;
use Preflow\Data\Describe\DescriptionContributor;
use Preflow\Data\FieldKinds;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Values\CoreValues;
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
 * In the one description (forType(), since 0.93) a type's "layouts" also has "output", the keys
 * the read output gives (RecordOutput with LayoutResource as its part) and their shapes:
 * {"_layout": "resolved_layout", "_areas": "area_map", "_unplaced": "area_map"}, where
 * "resolved_layout" is {"key", "label", "pinned", "fallback", "stored"} or null
 * (OUTPUT_SHAPES). toArray() keeps its 0.92 keys.
 *
 * A field of the type "areas" (since 0.92: the columns block's columns) names its set in
 * "config.layouts" (a key of "area_layout_sets") and its value shape in "value":
 *
 * - "areas_value": A layout and its areas: an object {"layout": a key of the field's area layout
 *   set or null, "resolved": {"key", "fallback"} or null, "areas": the shown areas, "unplaced":
 *   stored areas the layout lacks}, each area a list of blocks. Written as {"layout", "areas"}.
 *   (AreasFieldValue::SHAPES, the text the one description's "values" gives.)
 *
 * An empty map is an object ({}), never a list. "version" grows when a key changes meaning or goes;
 * a new key may be added within a version.
 *
 * Since 0.93 it is also the blocks package's part of the one description of every type
 * (Preflow\Data\Describe\Description, a DescriptionContributor): sections() gives "blocks",
 * "layouts" and "area_layout_sets", forType() a type's "layouts", shapes() the shapes they name.
 * There each block field also has "value", the shape the read output gives it in (as a type's
 * field), and "translatable" is true only on a site with more than one language (the output's
 * map). toArray() keeps its 0.92 output.
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

    /**
     * The shapes only the read output has (since 0.93; the one description's type "layouts"
     * names them under "output"): what RecordOutput gives for a type with layouts.
     */
    public const array OUTPUT_SHAPES = [
        'resolved_layout' => 'The layout a page shows with: an object {"key": a key of "layouts", "label", "pinned": true when the page is pinned to it, "fallback": true when the stored or pinned key could not be used, "stored": the key stored on the page or null}, or null when the type allows no layout that exists.',
    ];

    /** The read output's keys of a type with layouts and their shapes (the one description's "output"). */
    private const array OUTPUT = [PageLayouts::LAYOUT => 'resolved_layout', PageLayouts::AREAS => 'area_map', LayoutResource::UNPLACED => 'area_map'];

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
            ...$this->sectionsOf(described: false),
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
        return $this->sectionsOf(described: true);
    }

    /**
     * The sections; $described (the one description, sections()): each block field also names
     * its value's shape ("value", as a type's fields do) and is "translatable" only on a site with
     * more than one language, as the read output gives it. toArray() keeps 0.92's keys.
     *
     * @return array{blocks: array<string, mixed>|\stdClass, layouts: array<string, mixed>|\stdClass, area_layout_sets: array<string, mixed>|\stdClass}
     */
    private function sectionsOf(bool $described): array
    {
        $blocks = [];
        foreach ($this->blocks->all() as $key => $block) {
            $blocks[$key] = $this->block($block, $described);
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
     * A type's "layouts" in the one description, as toArray() gives it under "types", plus (only
     * here, toArray() keeps 0.92's keys) "output": the keys the read output gives for the type
     * (LayoutResource as RecordOutput's part) with their shapes, {"_layout": "resolved_layout",
     * "_areas": "area_map", "_unplaced": "area_map"}; "values" names the keys as stored and
     * written. Nothing for a type without layouts.
     *
     * @return array<string, mixed>
     * @since 0.93.0
     */
    public function forType(TypeDefinition $def): array
    {
        $layouts = $this->typeLayouts($def->key);

        return $layouts === null ? [] : ['layouts' => $layouts + ['output' => self::OUTPUT]];
    }

    /**
     * The shapes its sections and type entries name: "layout_key", "area_map" and (the read
     * output's) "resolved_layout" when a type has layouts, and each block field's shape (its
     * value's text, else the core's; "areas_value" for an areas field).
     *
     * @return array<string, string>
     * @since 0.93.0
     */
    public function shapes(): array
    {
        $shapes = [];
        foreach ($this->types?->keys() ?? [] as $type) {
            if ($this->pageLayouts?->forType($type) !== null) {
                $shapes = self::SHAPES + self::OUTPUT_SHAPES;
                break;
            }
        }
        // Each block field's shape (the one description names it in the field's "value"), with
        // its value's text, else the core's.
        foreach ($this->blocks->all() as $block) {
            foreach ($block->fields as $field) {
                $value = $this->kinds->valueOf($field);
                $shape = $value->shape($field);
                $text = $value instanceof DescribesShapes ? ($value->shapes()[$shape] ?? null) : null;
                $text ??= CoreValues::SHAPES[$shape] ?? null;
                if ($text !== null && !array_key_exists($shape, $shapes)) {
                    $shapes[$shape] = $text;
                }
                if ($field->type === BlockLists::AREAS) {
                    $shapes += AreasFieldValue::SHAPES;
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
    private function block(BlockDefinition $block, bool $described): array
    {
        $fields = [];
        foreach ($block->fields as $name => $field) {
            $fields[$name] = $this->field($field, $described);
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
    private function field(TypeFieldDefinition $field, bool $described): array
    {
        $out = [
            'type' => $field->type,
            'label' => $field->label,
            'help' => $field->help,
            'translatable' => $field->translatable && (!$described || $this->multilingual()),
            'required' => in_array('required', $field->validate, true),
            'list' => $this->kinds->holdsList($field),
            'config' => self::map($field->config),
        ];
        if ($described) {
            // Its value's shape, as the read output gives it (BlocksFieldValue::outputBlock()).
            $out['value'] = $this->kinds->valueOf($field)->shape($field);
        } elseif ($field->type === BlockLists::AREAS) {
            // Its layouts are those of the set "config.layouts" names (under "area_layout_sets").
            $out['value'] = 'areas_value';
        }

        return $out;
    }

    /** Whether the site has more than one content language (a translatable block field is then a map). */
    private function multilingual(): bool
    {
        return $this->types?->locales()->isMultilingual() ?? true;
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
