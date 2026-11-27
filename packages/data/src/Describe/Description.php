<?php

declare(strict_types=1);

namespace Preflow\Data\Describe;

use Preflow\Data\FieldKinds;
use Preflow\Data\RecordTitle;
use Preflow\Data\Relation;
use Preflow\Data\TypeAction;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;

/**
 * Every type the site declares and its fields as one JSON-serializable description, for an
 * OpenAPI or GraphQL schema builder (or a client that builds its forms), plus what packages add
 * (DescriptionContributor; the blocks package: "blocks", "layouts", "area_layout_sets"):
 *
 *     {"version": 1,
 *      "types": {"job": {"label": "Job", "id": "uuid", "actions": ["create", "update", "delete"],
 *                        "locales": ["en", "de"], "title_field": "title",
 *                        "fields": {"title": {"type": "string", "label": "Title", "help": null, "required": true, "list": false,
 *                                             "translatable": true, "unique": false, "value": "string", "config": {}},
 *                                   "company": {"type": "relation", …, "target": "company", "value": "id", "config": {"relation": {"to": "company"}}}}}},
 *      "values": {"id": "The id of one record, as a string.", "string": "Text, as a string."},
 *      "blocks": {…}, "layouts": {…}, "area_layout_sets": {…}}
 *
 * Declared data only: no PHP class names, no template paths, labels as declared (a key-shaped
 * label stays the key, a label per language stays the map, none is null; translating is the
 * API's choice, through DataManager::labels()), so every site and language gets the same
 * description of the same declarations.
 *
 * A type: "label" its declared label; "id" the field that holds a record's id; "actions" what an
 * editor or API may do with its records (TypeDefinition::allows(): "create", "update",
 * "delete"; reading is never restricted); "locales" the content languages; "title_field" the
 * field that names a record (RecordTitle::field()), or null (named by its id); "fields" its fields
 * in declared order. A contributor may add keys (blocks: "layouts").
 *
 * A field: "type" its field type; "label", "help" as declared; "required" its "validate" has
 * "required"; "list" its value is a list (FieldKinds::holdsList()); "translatable" its value is a
 * map of locale => value (only on a site with more than one language); "unique" false, true, or
 * the fields that scope its uniqueness; "target" (a relation field only) the related type, or null
 * when it names none; "value" the name of its output value's shape (FieldValue::shape(); "raw":
 * as stored, for a field type that registered no value); "config" its other options as declared.
 *
 * "values" has each shape a field or a contributor names, once, sorted, with what it is (the
 * FieldValue's DescribesShapes, else the core's text, else null). The shapes are frozen within a
 * version.
 *
 * An empty map is an object ({}), never a list. "version" grows when a key changes meaning or goes;
 * a new key may be added within a version.
 *
 * @since 0.93.0
 */
final readonly class Description
{
    public const int VERSION = 1;

    /** The description's own top-level keys: no contributor section may take one. */
    private const array CORE_KEYS = ['version', 'types', 'values'];

    public function __construct(
        private TypeRegistry $types,
        private FieldKinds $kinds,
        private DescriptionContributors $contributors,
    ) {}

    /**
     * @return array<string, mixed> "version", "types", "values" and the contributors' sections
     * @throws \LogicException a contributor's section or type key named like one of the description's own, or like another contributor's
     */
    public function toArray(): array
    {
        $contributors = $this->contributors->all();
        /** @var array<string, string|null> $shapes */
        $shapes = [];

        $types = [];
        foreach ($this->types->keys() as $key) {
            $def = $this->types->get($key);
            $types[$key] = $this->type($def, $shapes);
            foreach ($contributors as $contributor) {
                foreach ($contributor->forType($def) as $name => $value) {
                    if (array_key_exists($name, $types[$key])) {
                        throw new \LogicException(sprintf('A description contributor adds "%s" to the type "%s", which the type has already: name it otherwise.', $name, $key));
                    }
                    $types[$key][$name] = $value;
                }
            }
        }

        $sections = [];
        foreach ($contributors as $contributor) {
            foreach ($contributor->sections() as $name => $section) {
                if (in_array($name, self::CORE_KEYS, true) || array_key_exists($name, $sections)) {
                    throw new \LogicException(sprintf('A description contributor adds the section "%s", which the description has already: name it otherwise.', $name));
                }
                $sections[$name] = $section;
            }
            if ($contributor instanceof DescribesShapes) {
                foreach ($contributor->shapes() as $shape => $text) {
                    $shapes[$shape] ??= $text;
                }
            }
        }
        ksort($shapes);

        return [
            'version' => self::VERSION,
            'types' => self::map($types),
            'values' => self::map($shapes),
            ...$sections,
        ];
    }

    /**
     * @param array<string, string|null> $shapes the shapes named so far, with their text; this type's added
     * @return array<string, mixed>
     */
    private function type(TypeDefinition $def, array &$shapes): array
    {
        $translatable = $def->translatableFields();
        $fields = [];
        foreach ($def->fields as $name => $field) {
            $fields[$name] = $this->field($def, $field, in_array($name, $translatable, true), $shapes);
        }

        return [
            'label' => self::declared($this->types->schema($def->key)['label'] ?? null),
            'id' => $def->idField,
            'actions' => array_values(array_map(
                static fn (TypeAction $action): string => $action->value,
                array_filter(TypeAction::cases(), $def->allows(...)),
            )),
            'locales' => $def->locales->available,
            'title_field' => RecordTitle::field($def),
            'fields' => self::map($fields),
        ];
    }

    /**
     * @param array<string, string|null> $shapes
     * @return array<string, mixed>
     */
    private function field(TypeDefinition $def, TypeFieldDefinition $field, bool $translatable, array &$shapes): array
    {
        $value = $this->kinds->valueOf($field);
        $shape = $value->shape($field);
        if (!isset($shapes[$shape])) {
            $described = $value instanceof DescribesShapes ? ($value->shapes()[$shape] ?? null) : null;
            $shapes[$shape] = $described ?? CoreValues::SHAPES[$shape] ?? null;
        }

        $out = [
            'type' => $field->type,
            'label' => $field->labels !== [] ? $field->labels : $field->label,
            'help' => $field->helps !== [] ? $field->helps : $field->help,
            'required' => in_array('required', $field->validate, true),
            'list' => $this->kinds->holdsList($field),
            'translatable' => $translatable,
            'unique' => $field->unique,
        ];
        if ($this->kinds->isRelation($field->type)) {
            $out['target'] = Relation::fromField($def->key, $field, $this->kinds)?->target;
        }
        $out['value'] = $shape;
        $out['config'] = self::map($field->config);

        return $out;
    }

    /** A declared label: a string, a map per language, or null. */
    private static function declared(mixed $label): mixed
    {
        return is_string($label) || (is_array($label) && $label !== []) ? $label : null;
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
