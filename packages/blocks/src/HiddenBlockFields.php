<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Data\Records\RecordOutput;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\Message;

/**
 * Block fields declared "hidden": true (0.94), as a type's own fields since 0.93
 * (RecordOutput::declaredHidden()): no API reads them (BlocksFieldValue::outputBlock()), describes
 * them (ContentDescription) or writes them — a write that gives one is refused with
 * validation.record.unknown_field at "<prefix><uid>.<field>" (nested "<prefix><uid>.<child>.<cuid>.<field>",
 * an areas field's "<prefix><uid>.<child>.areas.<area>.<cuid>.<field>"), and a block written
 * without one keeps the value the stored block with its uid holds. Folio's form edits them (it is
 * no API path): its merge takes them (LayoutInput::merge(hiddenFields: true)).
 *
 * @internal
 * @since 0.94.0
 */
final class HiddenBlockFields
{
    /** Whether the block field is declared "hidden": true. */
    public static function declared(TypeFieldDefinition $field): bool
    {
        return RecordOutput::declaredHidden($field);
    }

    /**
     * The refusals of the declared hidden fields the blocks of a value hold, at any depth.
     *
     * @param string $prefix the value's key with a trailing dot ("sections.", "columns.")
     * @return array<string, Message>
     */
    public static function given(BlockRegistry $blocks, TypeFieldDefinition $field, mixed $value, string $prefix): array
    {
        $refused = [];
        foreach (BlockLists::of($field, $value) as $path => $list) {
            $refused += self::inList($blocks, $list, $prefix . ($path === '' ? '' : $path . '.'));
        }

        return $refused;
    }

    /**
     * given() for one list of blocks; a block is named by its "_uid", or by its place when it has
     * none (a new block).
     *
     * @param array<mixed> $list
     * @param string       $prefix the list's key with a trailing dot ("_areas.main.")
     * @return array<string, Message>
     */
    public static function inList(BlockRegistry $blocks, array $list, string $prefix): array
    {
        $refused = [];
        foreach (array_values($list) as $index => $block) {
            $definition = is_array($block) ? $blocks->get(is_string($block['_block'] ?? null) ? $block['_block'] : '') : null;
            if ($definition === null) {
                continue;
            }
            $uid = is_string($block['_uid'] ?? null) && $block['_uid'] !== '' ? $block['_uid'] : (string) $index;
            foreach ($definition->fields as $name => $field) {
                if (!array_key_exists($name, $block)) {
                    continue;
                }
                if (self::declared($field)) {
                    $refused[$prefix . $uid . '.' . $name] = new Message('validation.record.unknown_field', ['type' => $definition->label, 'field' => $name]);
                } elseif (BlockLists::holds($field)) {
                    $refused += self::given($blocks, $field, $block[$name], $prefix . $uid . '.' . $name . '.');
                }
            }
        }

        return $refused;
    }

    /**
     * The block with each declared hidden field it leaves out taken from $previous (the stored
     * block with its uid and key), this block only.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    public static function keepStored(BlockDefinition $definition, array $block, array $previous): array
    {
        foreach ($definition->fields as $name => $field) {
            if (self::declared($field) && !array_key_exists($name, $block) && array_key_exists($name, $previous)) {
                $block[$name] = $previous[$name];
            }
        }

        return $block;
    }

    /**
     * keepStored() for every block of a list and the lists nested in its blocks (each matched by
     * uid and key in the stored list at the same place); entries that are no known block stay as
     * given.
     *
     * @param array<mixed> $list
     * @param array<mixed> $previous the stored list
     * @return array<mixed>
     */
    public static function keepList(BlockRegistry $blocks, array $list, array $previous): array
    {
        $byUid = [];
        foreach ($previous as $block) {
            if (is_array($block) && is_string($block['_uid'] ?? null)) {
                $byUid[$block['_uid']] ??= $block;
            }
        }
        foreach ($list as $i => $block) {
            $definition = is_array($block) ? $blocks->get(is_string($block['_block'] ?? null) ? $block['_block'] : '') : null;
            if ($definition === null) {
                continue;
            }
            $was = is_string($block['_uid'] ?? null) ? ($byUid[$block['_uid']] ?? []) : [];
            if (($was['_block'] ?? null) !== $definition->key) {
                $was = [];
            }
            $list[$i] = self::keepStored($definition, self::keepNested($blocks, $definition, $block, $was), $was);
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    private static function keepNested(BlockRegistry $blocks, BlockDefinition $definition, array $block, array $previous): array
    {
        foreach ($definition->fields as $name => $field) {
            if (!BlockLists::holds($field) || !array_key_exists($name, $block) || ($previous[$name] ?? null) === null) {
                continue;
            }
            $before = BlockLists::of($field, $previous[$name]);
            $kept = BlockLists::map($field, $block[$name], static fn (array $list, string $path): array => array_values(self::keepList($blocks, $list, $before[$path] ?? [])));
            // Nothing kept: the value as given (its shape, JSON text included).
            if ($kept !== BlockLists::map($field, $block[$name], static fn (array $list): array => $list)) {
                $block[$name] = $kept;
            }
        }

        return $block;
    }
}
