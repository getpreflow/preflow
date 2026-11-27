<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\Rules\AreaContents;
use Preflow\Data\FieldVisibility;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;

/**
 * What the areas of every areas field of a record hold (AreaContents::forField(), the field's set):
 * the record's own areas fields and those of the blocks inside its block lists (a columns block in
 * a blocks field, in a page's area, in another column), to any depth. Each value is checked against
 * its stored value, so a write is refused only for what it introduces, as for a page's areas: the
 * stored record's field, or the stored block's with the same uid and key (a new record or a new
 * block is checked as new).
 *
 * A layout key a write changes must be one of the set's ("<field>.layout"); a stored key the set
 * no longer has passes unchanged (the value shows with the set's fallback).
 *
 * Lists are walked where they are shown: the areas a layout shows (an area it does not show is
 * kept, not checked), the fields a block's show_when does not hide, and for a type with page
 * layouts the areas its layout shows. Problems are keyed by the area that is wrong,
 * "<field>.areas.<area>", nested "<field>.<uid>.<child>.areas.<area>", in a page's area
 * "_areas.<area>.<uid>.<child>.areas.<area>" (BlocksServiceProvider reports them on every
 * validated write, a RecordRules check). An areas field whose set no package registers is not
 * checked here: the boot refuses it.
 *
 * @since 0.92.0
 */
final readonly class AreasFieldChecks
{
    public function __construct(
        private AreaLayoutSets $sets,
        private BlockRegistry $blocks,
        private ?PageLayouts $layouts = null,
    ) {}

    /**
     * Whether a record of the type may hold an areas value: a field of its own, or a block list (its
     * own, or a page's areas) while some block has an areas field. Spares reading the stored record
     * (and the report's scan) for every other type.
     */
    public function appliesTo(TypeDefinition $def): bool
    {
        $lists = $this->layouts?->forType($def->key) !== null;
        foreach ($def->fields as $field) {
            if ($field->type === BlockLists::AREAS) {
                return true;
            }
            $lists = $lists || BlockLists::holds($field);
        }
        if (!$lists) {
            return false;
        }
        foreach ($this->blocks->all() as $block) {
            foreach ($block->fields as $field) {
                if ($field->type === BlockLists::AREAS) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Each wrong area's first problem.
     *
     * @param array<string, mixed>       $values the record's values
     * @param array<string, mixed>|null  $stored the stored record's values; null for a new record
     * @param array<string, list<mixed>> $given  the rules the caller gave per field ([] for a field: not checked)
     * @return array<string, Message>
     */
    public function problems(TypeDefinition $def, array $values, ?array $stored, array $given = []): array
    {
        $problems = [];
        $hidden = array_flip(FieldVisibility::hiddenFieldsOf($def, $values));
        foreach ($def->fields as $name => $field) {
            if (isset($hidden[$name]) || ($given[$name] ?? null) === [] || !BlockLists::holds($field)) {
                continue;
            }
            $this->field($field, $values[$name] ?? null, $stored[$name] ?? null, $stored !== null, (string) $name, $problems);
        }

        if ($this->layouts?->forType($def->key) !== null && ($given[PageLayouts::AREAS] ?? null) !== []) {
            $storedAreas = Placement::areasOf($stored[PageLayouts::AREAS] ?? null);
            foreach ($this->layouts->resolveValues($def, $values)?->placement->placed() ?? [] as $area => $list) {
                $this->list($list, $storedAreas[$area] ?? [], $stored !== null, PageLayouts::AREAS . '.' . $area, $problems);
            }
        }

        return $problems;
    }

    /**
     * @param mixed                  $stored the field's stored value
     * @param bool                   $known  whether there is a stored counterpart (record or block)
     * @param array<string, Message> $problems
     */
    private function field(TypeFieldDefinition $field, mixed $value, mixed $stored, bool $known, string $at, array &$problems): void
    {
        $set = BlockLists::setName($field);
        if ($field->type === BlockLists::AREAS && $set !== null && $this->sets->has($set)) {
            $layouts = $this->sets->get($set);
            // A changed key must be one of the set's; an unchanged one the set no longer has passes
            // (the value shows with the set's fallback, and saving it as shown keeps its key).
            $key = AreasValue::fromStored($value)->layout;
            if ($key !== null && $layouts->get($key) === null && (!$known || AreasValue::fromStored($stored)->layout !== $key)) {
                $problems[$at . '.layout'] ??= new Message('validation.layout.set_layout', [
                    'key' => $key,
                    'set' => $set,
                    'layouts' => implode(', ', array_map(static fn (AreaLayout $layout): string => $layout->key, $layouts->all())),
                ]);
            }
            // A stored record or block without the value holds it empty (as a page's "_areas").
            $rule = AreaContents::forField($layouts, $this->blocks, $known ? ($stored ?? []) : null);
            foreach ($rule->problems($value, new ValidationContext($at, [])) as $area => $message) {
                $problems[$at . '.areas.' . $area] ??= $message;
            }
        }

        $storedLists = BlockLists::of($field, $stored);
        foreach (BlockLists::of($field, $value, $this->sets) as $path => $list) {
            $this->list($list, $storedLists[$path] ?? [], $known, $path === '' ? $at : $at . '.' . $path, $problems);
        }
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param list<array<string, mixed>> $stored the stored list at the same place
     * @param array<string, Message>     $problems
     */
    private function list(array $list, array $stored, bool $known, string $at, array &$problems): void
    {
        $byUid = [];
        foreach ($stored as $index => $block) {
            $byUid[BlockLists::uidOf($block, $index)] ??= $block;
        }
        foreach ($list as $index => $block) {
            $key = (string) ($block['_block'] ?? '');
            $definition = $this->blocks->get($key);
            if ($definition === null) {
                continue;
            }
            $uid = BlockLists::uidOf($block, $index);
            $was = $known && isset($byUid[$uid]) && ($byUid[$uid]['_block'] ?? null) === $key ? $byUid[$uid] : null;
            $hidden = array_flip($this->blocks->hiddenFieldsOf($key, $block));
            foreach ($definition->fields as $name => $field) {
                if (!isset($hidden[$name]) && BlockLists::holds($field)) {
                    $this->field($field, $block[$name] ?? null, $was[$name] ?? null, $was !== null, $at . '.' . $uid . '.' . $name, $problems);
                }
            }
        }
    }
}
