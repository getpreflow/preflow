<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockDefinition;
use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Data\FieldVisibility;
use Preflow\Data\TypeDefinition;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidatorFactory;

/**
 * A page's layout and areas from a write (an API body, a form), merged into its values:
 *
 *     {"layout": "standard", "areas": {"main": [{"_uid": "…", "_block": "text", "body": {"en": "…"}}, {"_block": "text"}]}}
 *
 * "layout" (optional; a layout key, or null for the type's default) becomes "_layout". "areas"
 * (optional) replaces only the areas it names (a list [] empties one); every area it does not name
 * keeps its stored blocks, unplaced ones included, so a write never loses content it did not touch.
 * A block without "_uid" (or with null or "") gets a fresh one (16 hex digits, nested blocks too); a field its show_when
 * hides keeps its stored value (FieldVisibility::keepStored(), per block, as Folio's form does).
 * Other keys of the input are the caller's own fields: merge() leaves them alone.
 *
 * Refused here (ValidationException, each key the stored key the core rules use): an area the
 * chosen layout does not have and the page does not store ("_areas.<name>"), a uid that is not 16
 * hex digits or is given to two blocks ("_areas.<name>.<i>._uid", nested
 * "_areas.<name>.<i>.<field>.<j>._uid"), a value of the wrong shape, a new block of a key no class
 * declares. Save the result with DataManager::saveType(): the core rules decide the rest, as for
 * Folio (the layout choice, a pin, the areas' limits, the block values).
 *
 * @since 0.92.0
 */
final readonly class LayoutInput
{
    public function __construct(
        private PageLayouts $layouts,
        private BlockRegistry $blocks,
        private ValidatorFactory $validators,
    ) {}

    /**
     * The record's values after $input.
     *
     * @param array<string, mixed> $stored the record's values before this write (the caller may
     *        have applied its own fields already); [] for a new record
     * @param array<string, mixed> $input  the body: "layout", "areas", and the caller's own keys
     * @return array<string, mixed>
     * @throws ValidationException naming the stored key ("_layout", "_areas", "_areas.<name>…")
     */
    public function merge(TypeDefinition $def, array $stored, array $input): array
    {
        $type = $this->layouts->forType($def->key);
        $errors = [];
        if ($type === null) {
            foreach (['layout' => PageLayouts::LAYOUT, 'areas' => PageLayouts::AREAS] as $key => $column) {
                if (array_key_exists($key, $input)) {
                    $errors[$column] = new Message('validation.layout.none', ['type' => $def->key]);
                }
            }
            $this->refuse($errors);

            return $stored;
        }

        $values = $stored;
        if (array_key_exists('layout', $input)) {
            $layout = $input['layout'];
            if ($layout !== null && !is_string($layout)) {
                $errors[PageLayouts::LAYOUT] = new Message('validation.layout.key');
            } else {
                $values[PageLayouts::LAYOUT] = $layout;
            }
        }
        if (array_key_exists('areas', $input)) {
            $values[PageLayouts::AREAS] = $this->areas($def, $values, $input['areas'], $errors);
        }
        $this->refuse($errors);

        return $values;
    }

    /**
     * The stored areas with the given ones replaced.
     *
     * @param array<string, mixed>   $values the values with the new layout
     * @param array<string, Message> $errors
     * @return array<string, list<array<string, mixed>>>
     */
    private function areas(TypeDefinition $def, array $values, mixed $given, array &$errors): array
    {
        $stored = Placement::areasOf($values[PageLayouts::AREAS] ?? null);
        if (!is_array($given) || ($given !== [] && array_is_list($given))) {
            $errors[PageLayouts::AREAS] = new Message('validation.layout.areas');

            return $stored;
        }
        $layout = $this->layouts->resolveValues($def, $values)?->layout;

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
            $at = PageLayouts::AREAS . '.' . $name;
            if (!isset($layout?->areas[$name]) && !array_key_exists($name, $stored)) {
                $errors[$at] = new Message('validation.layout.unknown_area', [
                    'layout' => $layout->key ?? (string) ($values[PageLayouts::LAYOUT] ?? ''),
                    'name' => $name,
                    'areas' => implode(', ', $layout?->areaNames() ?? array_keys($stored)),
                ]);
                continue;
            }
            if (!is_array($list) || ($list !== [] && !array_is_list($list))) {
                $errors[$at] = new Message('validation.layout.areas');
                continue;
            }
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
            $areas[$name] = $blocks;
        }

        return $areas;
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
            if (!array_key_exists($name, $block) || BlockLists::of($field, null) === []) {
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
            $rules[$key] = [new class ($message) implements ValidationRule {
                public function __construct(private readonly Message $message) {}

                public function validate(mixed $value, ValidationContext $context): Message
                {
                    return $this->message;
                }
            }];
        }

        throw new ValidationException($this->validators->make($rules, [])->validate());
    }
}
