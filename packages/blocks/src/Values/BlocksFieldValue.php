<?php

declare(strict_types=1);

namespace Preflow\Blocks\Values;

use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Data\FieldKinds;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CleansOnWrite;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\ListsReferences;
use Preflow\Data\Values\ListsVisibleReferences;
use Preflow\Data\Values\StoredCleaning;

/**
 * A "blocks" field's value (0.93), shape "blocks": a list of blocks, each an object with "_uid",
 * "_block" (a block key) and its fields. Each block field is read and written through its own
 * value (FieldKinds::valueOf(): a number stored as text reads as a number, rich text is cleaned,
 * a nested blocks or areas field through this value or AreasFieldValue, to any depth); a
 * translatable block field keeps its locale map, each locale through the value. A block no class
 * declares, and a key its block does not declare, are kept as given.
 *
 * - toStored(): a list (or its JSON text) with each block's fields through their toStored(); any
 *   other input passes through for the rules.
 * - toOutput(): the stored list (JSON text on SQL included) as BlockRegistry::visibleBlock() reads
 *   it (a field its show_when hides is null), each field through its toOutput(); none is [].
 * - clean() (on every typed write, StoredCleaning): each block's fields cleaned that clean on
 *   write (rich text), in every list the value holds (BlockLists::map()); a clean value comes back
 *   as given, the same array or the same JSON text, so its bytes and the change date stay; a
 *   cleaned JSON text stays JSON text.
 * - references(): the records every block field references (ListsReferences), by target type, each
 *   id once (a hidden field's too: media's usage check counts them). visibleReferences()
 *   (ListsVisibleReferences, 0.93): the same without the fields their show_when hides, for the
 *   read side.
 *
 * The same walk serves an areas field (AreasFieldValue) and a page's "_areas" through the methods
 * marked @internal.
 *
 * @since 0.93.0
 */
final class BlocksFieldValue implements FieldValue, CleansOnWrite, ListsReferences, ListsVisibleReferences, DescribesShapes
{
    public const array SHAPES = [
        'blocks' => 'A list of blocks: each an object with "_uid", "_block" (a key of "blocks") and its fields, each in its own shape (a translatable one a map of locale => value).',
    ];

    private ?StoredCleaning $cleaning = null;

    /**
     * @param \Closure(): BlockRegistry $blocks asked on use: the registry is built on the first
     *        read or write, not when the value is registered
     * @param FieldKinds $kinds the kinds whose values a block's fields use (the DataManager's)
     */
    public function __construct(
        private readonly \Closure $blocks,
        private readonly FieldKinds $kinds,
    ) {}

    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        $list = self::decoded($input);
        if (!is_array($list)) {
            return $input;
        }

        return array_map(fn (mixed $block): mixed => is_array($block) ? $this->storedBlock($block) : $block, $list);
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        $list = self::decoded($stored);
        if ($list === null || $list === '') {
            return [];
        }
        if (!is_array($list)) {
            return $stored;
        }

        return array_values(array_map($this->outputBlock(...), array_values(array_filter($list, 'is_array'))));
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'blocks';
    }

    public function shapes(): array
    {
        return self::SHAPES;
    }

    public function clean(mixed $value, TypeFieldDefinition $field): mixed
    {
        // A translatable field's map (StoredCleaning hands it decoded): each locale's list.
        if ($field->translatable && $field->type === BlockLists::BLOCKS && is_array($value) && $value !== [] && !array_is_list($value)) {
            foreach ($value as $locale => $one) {
                $value[$locale] = $this->cleanLists($one, $field);
            }

            return $value;
        }

        return $this->cleanLists($value, $field);
    }

    public function references(mixed $stored, TypeFieldDefinition $field): array
    {
        return $this->walkReferences($stored, $field, visible: false);
    }

    /**
     * references() without what a block field its show_when hides holds (it reads null), at any
     * depth (nested blocks, columns): what the read side shows.
     */
    public function visibleReferences(mixed $stored, TypeFieldDefinition $field): array
    {
        return $this->walkReferences($stored, $field, visible: true);
    }

    /** @return array<string, list<string>> */
    private function walkReferences(mixed $stored, TypeFieldDefinition $field, bool $visible): array
    {
        $found = [];
        foreach (BlockLists::of($field, $stored) as $list) {
            foreach ($list as $block) {
                $this->blockReferences($block, $found, $visible);
            }
        }

        return self::unique($found);
    }

    /**
     * The value of a field holding blocks (a blocks field, an areas field: BlockLists) with every
     * block cleaned: the value as given when nothing changed; JSON text stays JSON text.
     *
     * @internal
     */
    public function cleanLists(mixed $value, TypeFieldDefinition $field): mixed
    {
        $decoded = self::decoded($value);
        if (!is_array($decoded)) {
            return $value;
        }
        $clean = fn (array $list): array => array_map($this->cleanBlock(...), $list);
        $cleaned = BlockLists::map($field, $decoded, $clean);
        if ($cleaned === BlockLists::map($field, $decoded, static fn (array $list): array => $list)) {
            return $value;
        }

        return is_string($value) ? json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $cleaned;
    }

    /**
     * A page's "_areas" (area name => list of blocks, or its JSON text) with every block cleaned,
     * every stored area included: the value as given when nothing changed; JSON text stays JSON text.
     *
     * @internal
     */
    public function cleanAreaMap(mixed $areas): mixed
    {
        $decoded = self::decoded($areas);
        if (!is_array($decoded) || $decoded === []) {
            return $areas;
        }
        $field = new TypeFieldDefinition('_areas', BlockLists::AREAS);
        $value = ['layout' => null, 'areas' => $decoded];
        $cleaned = BlockLists::map($field, $value, fn (array $list): array => array_map($this->cleanBlock(...), $list));
        if ($cleaned === BlockLists::map($field, $value, static fn (array $list): array => $list)) {
            return $areas;
        }
        $map = $cleaned['areas'];

        return is_string($areas) ? json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $map;
    }

    /**
     * A block as an API reads it: visibleBlock() (hidden fields null), each declared field through
     * its value's toOutput(); a block no class declares as given.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     * @internal
     */
    public function outputBlock(array $block): array
    {
        $registry = ($this->blocks)();
        $key = is_string($block['_block'] ?? null) ? $block['_block'] : '';
        $definition = $registry->get($key);
        if ($definition === null) {
            return $block;
        }
        $hidden = array_flip($registry->hiddenFieldsOf($key, $block));
        $block = $registry->visibleBlock($block);
        foreach ($definition->fields as $name => $field) {
            if (!array_key_exists($name, $block) || isset($hidden[$name])) {
                continue;
            }
            $value = $this->kinds->valueOf($field);
            $block[$name] = self::perLocale($block[$name], $field, static fn (mixed $one): mixed => $value->toOutput($one, $field));
        }

        return $block;
    }

    /**
     * A block cleaned on write: its declared fields that clean on write (StoredCleaning::fields());
     * a block no class declares as given.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     * @internal
     */
    public function cleanBlock(array $block): array
    {
        $definition = ($this->blocks)()->get(is_string($block['_block'] ?? null) ? $block['_block'] : '');

        return $definition === null ? $block : $this->cleaning()->fields($block, $definition->fields);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, list<string>> $found
     */
    private function blockReferences(array $block, array &$found, bool $visible = false): void
    {
        $registry = ($this->blocks)();
        $key = is_string($block['_block'] ?? null) ? $block['_block'] : '';
        $definition = $registry->get($key);
        if ($definition === null) {
            return;
        }
        $hidden = $visible ? array_flip($registry->hiddenFieldsOf($key, $block)) : [];
        foreach ($definition->fields as $name => $field) {
            if (!array_key_exists($name, $block) || isset($hidden[$name])) {
                continue;
            }
            $value = $this->kinds->valueOf($field);
            if (!$value instanceof ListsReferences) {
                continue;
            }
            $values = $field->translatable && is_array($block[$name]) && $block[$name] !== [] && !array_is_list($block[$name]) && !BlockLists::holds($field)
                ? array_values($block[$name]) : [$block[$name]];
            foreach ($values as $one) {
                $references = $visible && $value instanceof ListsVisibleReferences ? $value->visibleReferences($one, $field) : $value->references($one, $field);
                foreach ($references as $type => $ids) {
                    $found[$type] ??= [];
                    array_push($found[$type], ...$ids);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function storedBlock(array $block): array
    {
        $definition = ($this->blocks)()->get(is_string($block['_block'] ?? null) ? $block['_block'] : '');
        if ($definition === null) {
            return $block;
        }
        foreach ($definition->fields as $name => $field) {
            if (array_key_exists($name, $block)) {
                $value = $this->kinds->valueOf($field);
                $block[$name] = self::perLocale($block[$name], $field, static fn (mixed $one): mixed => $value->toStored($one, $field));
            }
        }

        return $block;
    }

    /** $step on the value, or on each locale of a translatable field's map. */
    private static function perLocale(mixed $value, TypeFieldDefinition $field, \Closure $step): mixed
    {
        if ($field->translatable && is_array($value) && $value !== [] && !array_is_list($value) && !BlockLists::holds($field)) {
            return array_map($step, $value);
        }

        return $step($value);
    }

    /**
     * Each target type's ids once, in the order found.
     *
     * @param array<string, list<string>> $found
     * @return array<string, list<string>>
     */
    private static function unique(array $found): array
    {
        $out = [];
        foreach ($found as $type => $ids) {
            if ($ids !== []) {
                $out[$type] = array_values(array_unique($ids));
            }
        }

        return $out;
    }

    /** JSON text (a SQL driver's) decoded; anything else as given; text that is no JSON as given. */
    private static function decoded(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if (trim($value) === '') {
            return '';
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }

    private function cleaning(): StoredCleaning
    {
        return $this->cleaning ??= new StoredCleaning($this->kinds);
    }
}
