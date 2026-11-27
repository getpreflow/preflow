<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockDefinition;
use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Data\FieldVisibility;
use Preflow\Data\Records\InputPart;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * A page's layout and areas from a write (an API body, a form), merged into its values:
 *
 *     {"_layout": "standard", "_areas": {"main": [{"_uid": "…", "_block": "text", "body": {"en": "…"}}, {"_block": "text"}]}}
 *
 * The keys are the stored system keys (PageLayouts::LAYOUT, PageLayouts::AREAS): a key with an
 * underscore is never a type's own field, so a field named "layout" or "areas" is the caller's.
 * "_layout" (optional; a layout key, or null for the type's default) is stored as given. "_areas"
 * (optional) replaces only the areas it names (a list [] empties one); every area it does not name
 * keeps its stored blocks, unplaced ones included, so a write never loses content it did not touch.
 * A block without "_uid" (or with null or "") gets a fresh one (16 hex digits, nested blocks too); a field its show_when
 * hides keeps its stored value (FieldVisibility::keepStored(), per block, as Folio's form does).
 * Other keys of the input are the caller's own fields: merge() leaves them alone.
 *
 * An area the chosen layout lacks is taken when the page stores it or another layout of the type
 * (one an editor may choose, or a pinned one) declares it: its blocks are kept, unplaced ("Not shown
 * in this layout"), as when an editor switches layouts before the page was ever saved.
 *
 * Refused here (ValidationException, each key the stored key the core rules use): an area no
 * layout of the type declares and the page does not store ("_areas.<name>"), a uid that is not 16
 * hex digits or is given to two blocks ("_areas.<name>.<i>._uid", nested
 * "_areas.<name>.<i>.<field>.<j>._uid"), a value of the wrong shape, a new block of a key no class
 * declares. Save the result with DataManager::saveType(): the core rules decide the rest, as for
 * Folio (the layout choice, a pin, the areas' limits, the block values).
 *
 * Only the top level is merged by area: an areas field (the columns block's columns) of a block in
 * a written list is taken as written, so its stored columns are replaced as a whole.
 *
 * mergeField() does the same for the value of an areas field ({"layout": …, "areas": {…}}, the
 * columns block's columns): its areas are kept, refused and merged by these rules, keyed under the
 * field.
 *
 * Since 0.93 it is the blocks package's part of the one write class (InputPart, registered on
 * RecordParts by BlocksServiceProvider): RecordInput hands it "_layout" and "_areas" of a type with
 * layouts (keys()) beside the type's fields, and an areas field's value through AreasFieldValue.
 * Write a record through RecordInput rather than calling merge() and mergeField() yourself: the
 * fields, the languages and the refusals of the rest of the body are then the core's too.
 *
 * @since 0.92.0
 */
final readonly class LayoutInput implements InputPart
{
    public function __construct(
        private PageLayouts $layouts,
        private BlockRegistry $blocks,
        private ValidatorFactory $validators,
        /** The area layout sets, for an areas field (mergeField(), 0.92). */
        private ?AreaLayoutSets $sets = null,
    ) {}

    /**
     * The keys this part takes for a type (0.93, InputPart): "_layout" and "_areas" for a type with
     * "layouts", else none (RecordInput then refuses them as no field of the type).
     *
     * @return list<string>
     */
    public function keys(TypeDefinition $def): array
    {
        return $this->layouts->forType($def->key) === null ? [] : [PageLayouts::LAYOUT, PageLayouts::AREAS];
    }

    /**
     * The record's values after $input.
     *
     * @param array<string, mixed> $stored the record's values before this write (the caller may
     *        have applied its own fields already); [] for a new record
     * @param array<string, mixed> $input  the body: "_layout", "_areas", and the caller's own keys
     * @return array<string, mixed>
     * @throws ValidationException naming the stored key ("_layout", "_areas", "_areas.<name>…")
     * As InputPart::merge() (0.93) RecordInput calls it; a site's own write calls RecordInput,
     * which also takes the fields, the languages and the refusals of the rest of the body.
     *
     * @see \Preflow\Data\Records\RecordInput
     */
    public function merge(TypeDefinition $def, array $stored, array $input): array
    {
        $type = $this->layouts->forType($def->key);
        $errors = [];
        if ($type === null) {
            foreach ([PageLayouts::LAYOUT, PageLayouts::AREAS] as $column) {
                if (array_key_exists($column, $input)) {
                    $errors[$column] = new Message('validation.layout.none', ['type' => $def->key]);
                }
            }
            $this->refuse($errors);

            return $stored;
        }

        $values = $stored;
        if (array_key_exists(PageLayouts::LAYOUT, $input)) {
            $layout = $input[PageLayouts::LAYOUT];
            if ($layout !== null && !is_string($layout)) {
                $errors[PageLayouts::LAYOUT] = new Message('validation.layout.key');
            } else {
                $values[PageLayouts::LAYOUT] = $layout;
            }
        }
        if (array_key_exists(PageLayouts::AREAS, $input)) {
            $values[PageLayouts::AREAS] = $this->areas(
                Placement::areasOf($values[PageLayouts::AREAS] ?? null),
                $input[PageLayouts::AREAS],
                $this->layouts->resolveValues($def, $values)?->layout,
                $this->layouts->declaredAreas($def->key),
                PageLayouts::AREAS,
                (string) ($values[PageLayouts::LAYOUT] ?? ''),
                $errors,
            );
        }
        $this->refuse($errors);

        return $values;
    }

    /**
     * An areas field's value after a write (since 0.92): the same rules as merge() for a page, one
     * level down. $input is {"layout": key (optional; a key of the field's set, or null for its
     * fallback), "areas": {name: [blocks…]} (optional)}: "areas" replaces only the areas it names,
     * every other stored area keeps its blocks (a column a narrower layout lacks is kept, not
     * shown). An area no layout of the set declares and the value does not store is refused, so
     * are a uid that is not 16 hex digits or is given twice, a value of the wrong shape and a new
     * block of a key no class declares; blocks without "_uid" get fresh ones, hidden fields keep
     * their stored values. Keys are the field's: "<field>", "<field>.layout",
     * "<field>.areas.<name>", "<field>.areas.<name>.<i>._uid". Save the record with
     * DataManager::saveType(): the core rules decide the rest (the layout key, the columns' limits
     * and allowed blocks, the block values).
     *
     * @param mixed $stored the field's stored value (AreasValue: an array or its JSON text; null for none)
     * @param mixed $input  the field's part of the body
     * @return array{layout: ?string, areas: array<string, list<array<string, mixed>>>}
     * @throws ValidationException naming the field's keys
     * @throws LayoutConfigException when the field is no areas field or its set is not registered
     * @deprecated since 0.93.0, removed no earlier than 0.95: write the record through RecordInput,
     *             which merges an areas field's value through its value (AreasFieldValue::merge())
     */
    public function mergeField(TypeFieldDefinition $field, mixed $stored, mixed $input): array
    {
        return $this->mergeAreasValue($field, $stored, $input);
    }

    /**
     * mergeField()'s merge, which AreasFieldValue::merge() calls (0.93): the same rules and keys.
     *
     * @internal
     * @return array{layout: ?string, areas: array<string, list<array<string, mixed>>>}
     * @throws ValidationException naming the field's keys
     * @throws LayoutConfigException when the field is no areas field or its set is not registered
     */
    public function mergeAreasValue(TypeFieldDefinition $field, mixed $stored, mixed $input): array
    {
        $set = $this->setOf($field);
        $value = AreasValue::fromStored($stored);
        $errors = [];
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            $errors[$field->name] = new Message('validation.layout.areas_value');
            $this->refuse($errors);
        }
        $layout = $value->layout;
        if (array_key_exists('layout', $input)) {
            if ($input['layout'] !== null && !is_string($input['layout'])) {
                $errors[$field->name . '.layout'] = new Message('validation.layout.areas_value');
            } else {
                $layout = $input['layout'] === '' ? null : $input['layout'];
            }
        }
        $areas = $value->areas;
        if (array_key_exists('areas', $input)) {
            $declared = [];
            foreach ($set->all() as $one) {
                array_push($declared, ...$one->areaNames());
            }
            $at = $field->name . '.areas';
            $areas = $this->areas($areas, $input['areas'], AreasValue::of($layout, $areas)->resolve($set), array_values(array_unique($declared)), $at, (string) $layout, $errors);
        }
        $this->refuse($errors);

        return AreasValue::of($layout, $areas)->toStored();
    }

    /**
     * The set an areas field names.
     *
     * @throws LayoutConfigException
     */
    private function setOf(TypeFieldDefinition $field): AreaLayoutSet
    {
        $name = BlockLists::setName($field);
        if ($field->type !== BlockLists::AREAS || $name === null || $this->sets === null) {
            throw new LayoutConfigException(sprintf('The field "%s" is no areas field with a set ("type": "areas", "layouts": "<set>"), or no area layout sets are given to LayoutInput.', $field->name));
        }

        return $this->sets->get($name);
    }

    /**
     * The stored areas with the given ones replaced (a page's "_areas", an areas field's "areas").
     *
     * @param array<string, list<array<string, mixed>>> $stored   the stored areas
     * @param AreaLayout|null                           $layout   the layout the value resolves to with the new key
     * @param list<string>                              $names    the area names any layout of the type or set declares
     * @param string                                    $at       the areas' key in errors ("_areas", "<field>.areas")
     * @param string                                    $key      the written layout key, for a message without a layout
     * @param array<string, Message>                    $errors
     * @return array<string, list<array<string, mixed>>>
     */
    private function areas(array $stored, mixed $given, ?AreaLayout $layout, array $names, string $at, string $key, array &$errors): array
    {
        if (!is_array($given) || ($given !== [] && array_is_list($given))) {
            $errors[$at] = new Message('validation.layout.areas');

            return $stored;
        }
        $declared = array_flip($names);

        // Every stored block by uid (a block may move between areas); the uids the areas the write
        // does not name still hold.
        $previous = [];
        $taken = [];
        foreach ($stored as $name => $blocks) {
            foreach ($blocks as $block) {
                $uid = $block['_uid'] ?? null;
                if (is_string($uid) && $uid !== '') {
                    $previous[$uid] ??= $block;
                    if (!array_key_exists($name, $given)) {
                        $taken[$uid] = true;
                    }
                }
            }
        }

        $areas = $stored;
        foreach ($given as $name => $list) {
            $name = (string) $name;
            $here = $at . '.' . $name;
            if (!isset($layout?->areas[$name]) && !array_key_exists($name, $stored) && !isset($declared[$name])) {
                $errors[$here] = new Message('validation.layout.unknown_area', [
                    'layout' => $layout->key ?? $key,
                    'name' => $name,
                    'areas' => implode(', ', $layout?->areaNames() ?? array_keys($stored)),
                ]);
                continue;
            }
            if (!is_array($list) || ($list !== [] && !array_is_list($list))) {
                $errors[$here] = new Message('validation.layout.areas');
                continue;
            }
            $areas[$name] = $this->list($list, $here, $previous, $taken, $errors);
        }

        return $areas;
    }

    /**
     * One area as written: each block checked, given a fresh uid when it has none, and merged with
     * the stored block of its uid.
     *
     * @param array<mixed>                        $list
     * @param string                              $at       the area's key in errors
     * @param array<string, array<string, mixed>> $previous every stored block by uid
     * @param array<array-key, true>              $taken    the uids already held
     * @param array<string, Message>              $errors
     * @return list<array<string, mixed>>
     */
    private function list(array $list, string $at, array $previous, array &$taken, array &$errors): array
    {
        $blocks = [];
        foreach ($list as $index => $block) {
            $here = $at . '.' . $index;
            $key = is_array($block) ? ($block['_block'] ?? null) : null;
            if (!is_string($key) || $key === '') {
                $errors[$here] = new Message('validation.layout.block');
                continue;
            }
            $uid = $block['_uid'] ?? null;
            if ($uid === null || $uid === '') {
                $block['_uid'] = $uid = self::freshUid();
            } elseif (!self::isUid($uid)) {
                $errors[$here . '._uid'] = self::uidFormat($uid);
                continue;
            } elseif (isset($taken[$uid])) {
                $errors[$here . '._uid'] = new Message('validation.layout.uid_twice', ['uid' => $uid]);
                continue;
            }
            $taken[$uid] = true;
            $was = $previous[$uid] ?? [];

            $definition = $this->blocks->get($key);
            if ($definition === null) {
                // A stored block no class declares any more stays as it is; a new one is a mistake.
                if (($was['_block'] ?? null) !== $key) {
                    $errors[$here . '._block'] = new Message('validation.layout.unknown_block', [
                        'block' => $key,
                        'blocks' => implode(', ', array_keys($this->blocks->all())),
                    ]);
                    continue;
                }
                $blocks[] = $block;
                continue;
            }
            $blocks[] = $this->kept($definition, $block, ($was['_block'] ?? null) === $key ? $was : [], $here, $errors);
        }

        return $blocks;
    }

    /**
     * The block as written, with fresh uids for nested blocks without one and its hidden fields'
     * stored values (here and in nested blocks, matched by uid).
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed>   $previous the stored block with the same uid and key; [] for a new one
     * @param string                 $at       the block's key in errors ("_areas.<name>.<i>")
     * @param array<string, Message> $errors
     * @return array<string, mixed>
     */
    private function kept(BlockDefinition $definition, array $block, array $previous, string $at, array &$errors): array
    {
        foreach ($definition->fields as $name => $field) {
            if (!array_key_exists($name, $block) || !BlockLists::holds($field)) {
                continue;
            }
            $before = BlockLists::of($field, $previous[$name] ?? null);
            $block[$name] = BlockLists::map($field, $block[$name], function (array $list, string $path) use ($before, $at, $name, &$errors): array {
                return $this->keptList($list, $before[$path] ?? [], $at . '.' . $name . ($path === '' ? '' : '.' . $path), $errors);
            });
        }

        return FieldVisibility::keepStored($definition->fields, $block, $previous);
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param list<array<string, mixed>> $previous the stored list
     * @param string                     $at       the list's key in errors ("_areas.<name>.<i>.<field>")
     * @param array<string, Message>     $errors
     * @return list<array<string, mixed>>
     */
    private function keptList(array $list, array $previous, string $at, array &$errors): array
    {
        $byUid = [];
        foreach ($previous as $block) {
            if (is_string($block['_uid'] ?? null)) {
                $byUid[$block['_uid']] ??= $block;
            }
        }
        $out = [];
        foreach ($list as $index => $block) {
            $here = $at . '.' . $index;
            $uid = $block['_uid'] ?? null;
            if ($uid === null || $uid === '') {
                $block['_uid'] = $uid = self::freshUid();
            } elseif (!self::isUid($uid)) {
                $errors[$here . '._uid'] = self::uidFormat($uid);
                $out[] = $block;
                continue;
            }
            $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
            $was = $byUid[$uid] ?? [];
            $out[] = $definition === null ? $block : $this->kept($definition, $block, ($was['_block'] ?? null) === $definition->key ? $was : [], $here, $errors);
        }

        return $out;
    }

    /** Whether $uid is a block uid as the core knows one (BlockLists::uidOf()): 16 hex digits. */
    private static function isUid(mixed $uid): bool
    {
        return is_string($uid) && preg_match('/^[a-f0-9]{16}$/', $uid) === 1;
    }

    private static function uidFormat(mixed $uid): Message
    {
        return new Message('validation.layout.uid_format', ['uid' => is_scalar($uid) ? (string) $uid : (string) json_encode($uid)]);
    }

    /** A block uid as Folio's editor makes one. */
    private static function freshUid(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Throws the refusals in the validator's language (the render's, else English).
     *
     * @param array<string, Message> $errors
     */
    private function refuse(array $errors): void
    {
        if ($errors === []) {
            return;
        }
        $rules = [];
        foreach ($errors as $key => $message) {
            $rules[$key] = [new Rules\MessageRule($message)];
        }

        throw new ValidationException($this->validators->make($rules, [])->validate());
    }
}
