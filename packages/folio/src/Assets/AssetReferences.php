<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets;

use Preflow\Blocks\BlockLists;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Blocks\BlockRegistry;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\ReferencesAssets;

/**
 * Walks the asset values of a record: the fields whose type implements ReferencesAssets
 * (asset, embed's poster, a link to a file, a site's own types; since 0.91) and, inside
 * the fields that hold blocks (BlockLists: a blocks field, the areas of an areas field, shown or
 * not), each known block's fields the same way, recursively. Unknown blocks are left
 * untouched. Without a field type registry only "asset" fields are read (as before 0.91). One
 * walker serves the usage scan and the legacy import.
 *
 * A type with page layouts (0.92) holds blocks in "_areas" too: every stored area is walked as a
 * blocks field, the areas its layout does not show included (switching back shows them again).
 */
final class AssetReferences
{
    /**
     * @param (\Closure(): FieldTypeRegistry)|null $fieldTypes the field types, asked which of them hold asset ids
     * @param (\Closure(): ?PageLayouts)|null      $layouts    the types' page layouts, asked on use
     */
    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly ?\Closure $fieldTypes = null,
        private readonly ?\Closure $layouts = null,
    ) {}

    /**
     * The system columns of the type that hold blocks besides its fields: "_areas" for a type
     * with page layouts (0.92), else none.
     *
     * @return list<string>
     */
    public function blockColumns(TypeDefinition $def): array
    {
        $layouts = $this->layouts !== null ? ($this->layouts)() : null;

        return $layouts?->forType($def->key) !== null ? [PageLayouts::AREAS] : [];
    }

    /** Whether a field holds asset ids itself (its type implements ReferencesAssets, or is "asset") or holds blocks that may. */
    public function holdsAssets(TypeFieldDefinition $field): bool
    {
        return BlockLists::holds($field) || $this->referencing($field->type) !== null || $field->type === 'asset';
    }

    private function referencing(string $type): ?ReferencesAssets
    {
        $registry = $this->fieldTypes !== null ? ($this->fieldTypes)() : null;
        $fieldType = $registry !== null && $registry->has($type) ? $registry->get($type) : null;

        return $fieldType instanceof ReferencesAssets ? $fieldType : null;
    }

    /**
     * Apply $fn to every asset value (each item of a list) and return the data
     * with the same shapes (JSON text stays JSON text).
     *
     * @param array<string, mixed>   $data
     * @param \Closure(string): string $fn
     * @return array<string, mixed>
     */
    public function map(TypeDefinition $def, array $data, \Closure $fn): array
    {
        $data = $this->mapFields($def->fields, $data, $fn);
        foreach ($this->blockColumns($def) as $column) {
            if (array_key_exists($column, $data)) {
                $data[$column] = $this->mapAreas($data[$column], $fn);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string> every asset value, in field order
     */
    public function collect(TypeDefinition $def, array $data): array
    {
        $found = [];
        $this->map($def, $data, static function (string $value) use (&$found): string {
            $found[] = $value;
            return $value;
        });

        return $found;
    }

    /**
     * @param array<string, TypeFieldDefinition> $fields
     * @param array<string, mixed>               $data
     * @return array<string, mixed>
     */
    private function mapFields(array $fields, array $data, \Closure $fn): array
    {
        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $data)) {
                continue;
            }
            if (BlockLists::holds($field)) {
                $data[$name] = $this->mapHeld($field, $data[$name], $fn);
            } elseif (($type = $this->referencing($field->type)) !== null) {
                $data[$name] = $type->mapAssetIds($data[$name], $field->config, $fn);
            } elseif ($field->type === 'asset') {
                $data[$name] = self::mapValue($data[$name], $fn);
            }
        }

        return $data;
    }

    /**
     * A field holding blocks (BlockLists: a blocks field, an areas field's areas, shown or not), each
     * list walked as a blocks field; JSON text stays JSON text.
     */
    private function mapHeld(TypeFieldDefinition $field, mixed $value, \Closure $fn): mixed
    {
        if ($field->type === BlockLists::BLOCKS) {
            return $this->mapBlocks($value, $fn);
        }
        $json = is_string($value);
        if ($json && json_decode($value, true) === null) {
            return $value;
        }
        $mapped = BlockLists::map($field, $value, fn (array $list): array => $this->mapList($list, $fn));

        return $json && is_array($mapped) ? json_encode($mapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $mapped;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    private function mapList(array $list, \Closure $fn): array
    {
        foreach ($list as $i => $block) {
            $definition = is_string($block['_block'] ?? null) ? $this->blocks->get($block['_block']) : null;
            if ($definition !== null) {
                $list[$i] = $this->mapFields($definition->fields, $block, $fn);
            }
        }

        return $list;
    }

    private function mapBlocks(mixed $value, \Closure $fn): mixed
    {
        $json = is_string($value);
        $blocks = $json ? json_decode($value, true) : $value;
        if (!is_array($blocks)) {
            return $value;
        }
        foreach ($blocks as $i => $block) {
            $definition = is_array($block) && is_string($block['_block'] ?? null) ? $this->blocks->get($block['_block']) : null;
            if ($definition !== null) {
                $blocks[$i] = $this->mapFields($definition->fields, $block, $fn);
            }
        }

        return $json ? json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $blocks;
    }

    /** A page's areas ({area: [blocks…]}, an array or JSON text), each area as a blocks field. */
    private function mapAreas(mixed $value, \Closure $fn): mixed
    {
        $json = is_string($value);
        $areas = $json ? json_decode($value, true) : $value;
        if (!is_array($areas) || $areas === []) {
            return $value;
        }
        foreach ($areas as $name => $blocks) {
            $areas[$name] = $this->mapBlocks($blocks, $fn);
        }

        return $json ? json_encode($areas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $areas;
    }

    /**
     * An asset field's value (AssetFieldType::mapAssetIds()): a path/id, a JSON list of them, a JSON object (the locale map SQL storage
     * keeps for a translatable field, values being ids or lists), a PHP list or
     * array, or a locale map of those. JSON text stays JSON text of the same
     * shape; invalid JSON is left untouched.
     */
    public static function mapValue(mixed $value, \Closure $fn): mixed
    {
        if (is_string($value)) {
            if (str_starts_with($value, '[')) {
                $list = json_decode($value, true);
                return is_array($list)
                    ? json_encode(array_map(static fn ($v) => self::mapValue($v, $fn), $list), JSON_UNESCAPED_SLASHES)
                    : $value;
            }
            if (str_starts_with($value, '{')) {
                $map = json_decode($value, true);
                return is_array($map)
                    ? ($map === [] ? $value : json_encode(array_map(static fn ($v) => self::mapValue($v, $fn), $map), JSON_UNESCAPED_SLASHES))
                    : $value;
            }
            return $value === '' ? $value : $fn($value);
        }
        if (is_array($value)) {
            return array_map(static fn ($v) => self::mapValue($v, $fn), $value);
        }

        return $value;
    }
}
