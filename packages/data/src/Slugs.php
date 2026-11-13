<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Validation\Rules\Slug;

/**
 * Slugs no other record has: Slug::clean() of a text, then "-2", "-3"... until free.
 */
final class Slugs
{
    /**
     * A slug for $text that no other record of $type has in $field: "senior-developer", then
     * "senior-developer-2", "-3"... $exceptId is the record being renamed, which may keep its own.
     * The result is at most $max characters long, suffix included; $fallback stands in for a text
     * that has no letters or digits.
     *
     * @param string $type a type key or a model class
     * @throws \InvalidArgumentException when the type has no such field, or the field is translatable (its
     *         value is a map per locale, which this cannot look up)
     */
    public static function unique(DataManager $data, string $type, string $text, string $field = 'slug', string|int|null $exceptId = null, int $max = 80, string $fallback = 'item'): string
    {
        $isClass = is_subclass_of($type, Model::class);
        if ($isClass) {
            $meta = ModelMetadata::for($type);
            $known = isset($meta->fields[$field]) || $field === $meta->idField;
            $idField = $meta->idField;
            if ($meta->type !== null && $registry = $data->getTypeRegistry()) {
                $translatable = $registry->has($meta->type) && in_array($field, $registry->get($meta->type)->translatableFields(), true);
            } else {
                $translatable = ($meta->fields[$field]->translatable ?? false) === true;
            }
        } else {
            $registry = $data->getTypeRegistry();
            if ($registry === null || !$registry->has($type)) {
                throw new \InvalidArgumentException("Unknown type \"{$type}\": it is neither a model class nor a type the registry holds.");
            }
            $def = $registry->get($type);
            $known = isset($def->fields[$field]);
            $idField = $def->idField;
            $translatable = in_array($field, $def->translatableFields(), true);
        }
        if (!$known) {
            throw new \InvalidArgumentException("The type \"{$type}\" has no field \"{$field}\" to hold a slug.");
        }

        if ($translatable) {
            throw new \InvalidArgumentException("The field \"{$field}\" of \"{$type}\" is translatable: Slugs::unique() works on a plain field.");
        }

        $base = self::base($text, $max, $fallback);
        $candidate = $base;
        for ($n = 2; ; $n++) {
            $query = $isClass ? $data->query($type) : $data->queryType($type);
            $query->where($field, $candidate);
            if ($exceptId !== null) {
                $query->where($idField, '!=', $exceptId);
            }
            if ($query->first() === null) {
                return $candidate;
            }
            $candidate = self::suffixed($base, $n, $max);
        }
    }

    /**
     * Like unique() for an import that holds its slugs in memory: the slug is added to $taken
     * (slug => true), so the next call sees it.
     *
     * @param array<string, true> $taken
     */
    public static function among(string $text, array &$taken, int $max = 80, string $fallback = 'item'): string
    {
        $base = self::base($text, $max, $fallback);
        $candidate = $base;
        for ($n = 2; isset($taken[$candidate]); $n++) {
            $candidate = self::suffixed($base, $n, $max);
        }
        $taken[$candidate] = true;

        return $candidate;
    }

    private static function base(string $text, int $max, string $fallback): string
    {
        $slug = self::cut(Slug::clean($text), $max);

        return $slug === '' ? self::cut($fallback, $max) : $slug;
    }

    /** The base with "-$n", the base shortened so the whole stays within $max. */
    private static function suffixed(string $base, int $n, int $max): string
    {
        $suffix = '-' . $n;

        return self::cut($base, max(1, $max - strlen($suffix))) . $suffix;
    }

    /** At most $max characters: at a hyphen when one is in the last half, else hard-cut; no trailing hyphen. */
    private static function cut(string $slug, int $max): string
    {
        if (strlen($slug) <= $max) {
            return $slug;
        }
        $head = substr($slug, 0, $max);
        $hyphen = $slug[$max] === '-' ? $max : strrpos($head, '-');
        if ($hyphen !== false && $hyphen >= $max / 2) {
            $head = substr($head, 0, $hyphen);
        }

        return rtrim($head, '-');
    }
}
