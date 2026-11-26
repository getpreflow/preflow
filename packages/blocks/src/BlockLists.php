<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Data\TypeFieldDefinition;

/**
 * The block lists a field's value holds: the one place that knows which field types hold blocks.
 * A "blocks" field holds one list, at the path "" under the field; every other type holds none.
 * Values may be arrays or their JSON text. Whoever walks blocks (the value checks, and since 0.92
 * the walkers that only knew "blocks") goes through of() and map(), so a type that holds blocks
 * elsewhere in its value is added here once.
 *
 * @since 0.92.0
 */
final class BlockLists
{
    /** A block's uid as Folio's editor stores it. */
    private const string UID = '/^[a-f0-9]{16}$/';

    /**
     * The block lists of the value, keyed by their path under the field ("" for a blocks field);
     * [] for a type that holds none. Each list holds the arrays of the value, in order.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function of(TypeFieldDefinition $field, mixed $value): array
    {
        if ($field->type !== 'blocks') {
            return [];
        }

        return ['' => self::listOf($value)];
    }

    /**
     * The value with each block list replaced by what $map gives for it (with its path), the
     * shape of the value kept; a value of a type that holds no blocks comes back as given.
     *
     * @param \Closure(list<array<string, mixed>> $list, string $path): list<array<string, mixed>> $map
     */
    public static function map(TypeFieldDefinition $field, mixed $value, \Closure $map): mixed
    {
        if ($field->type !== 'blocks') {
            return $value;
        }

        return $map(self::listOf($value), '');
    }

    /**
     * The uid a block is known by: its "_uid" when it is one ("<16 hex digits>"), else one derived
     * from its place and content, the same on every read (a hand-edited or imported block), as
     * Folio's editor gives it.
     *
     * @param array<string, mixed> $block
     * @param int $index its place among the arrays of its list
     */
    public static function uidOf(array $block, int $index): string
    {
        $uid = $block['_uid'] ?? null;
        if (is_string($uid) && preg_match(self::UID, $uid) === 1) {
            return $uid;
        }

        return hash('xxh3', 'folio-block:' . $index . ':' . json_encode($uid) . ':' . json_encode($block['_block'] ?? null));
    }

    /**
     * A stored list: an array or its JSON text; entries that are no arrays are left out.
     *
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
