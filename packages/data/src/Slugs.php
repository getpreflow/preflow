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
     * @throws \InvalidArgumentException when the type has no such field
     */
    public static function unique(DataManager $data, string $type, string $text, string $field = 'slug', ?string $exceptId = null, int $max = 80, string $fallback = 'item'): string
    {
        $isClass = is_subclass_of($type, Model::class);
        if ($isClass) {
            $meta = ModelMetadata::for($type);
            $known = isset($meta->fields[$field]) || $field === $meta->idField;
        } else {
            $registry = $data->getTypeRegistry();
            if ($registry === null || !$registry->has($type)) {
                throw new \InvalidArgumentException("Unknown type \"{$type}\": it is neither a model class nor a type the registry holds.");
            }
            $known = isset($registry->get($type)->fields[$field]);
        }
        if (!$known) {
            throw new \InvalidArgumentException("The type \"{$type}\" has no field \"{$field}\" to hold a slug.");
        }

        $base = self::base($text, $max, $fallback);
        $candidate = $base;
        for ($n = 2; ; $n++) {
            $query = $isClass ? $data->query($type) : $data->queryType($type);
            $found = $query->where($field, $candidate)->first();
            if ($found === null) {
                return $candidate;
            }
            $foundId = $found instanceof DynamicRecord ? $found->getId() : (string) $found->{$meta->idField};
            if ($exceptId !== null && $foundId === $exceptId) {
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
