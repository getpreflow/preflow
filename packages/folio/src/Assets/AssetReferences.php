<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets;

use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Folio\Block\BlockRegistry;

/**
 * Walks the asset values of a record: top-level "asset" fields and, inside
 * "blocks" fields, each known block's asset fields, recursively. Unknown blocks
 * are left untouched. One walker serves the usage scan and the legacy import.
 */
final class AssetReferences
{
    public function __construct(
        private readonly BlockRegistry $blocks,
    ) {}

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
        return $this->mapFields($def->fields, $data, $fn);
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
            if ($field->type === 'asset') {
                $data[$name] = self::mapValue($data[$name], $fn);
            } elseif ($field->type === 'blocks') {
                $data[$name] = $this->mapBlocks($data[$name], $fn);
            }
        }

        return $data;
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

    /**
     * A path/id, a JSON list of them, a JSON object (the locale map SQL storage
     * keeps for a translatable field, values being ids or lists), a PHP list or
     * array, or a locale map of those. JSON text stays JSON text of the same
     * shape; invalid JSON is left untouched.
     */
    private static function mapValue(mixed $value, \Closure $fn): mixed
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
