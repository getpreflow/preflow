<?php

declare(strict_types=1);

namespace Preflow\Auth\Record;

/**
 * A stored roles field as it may arrive: a list, a JSON array string (as SQL returns a stored
 * list, also as an entry of a list), a comma-separated string, an int, or nothing. The one rule
 * RecordUser reads roles by; the members core reads member state by it too.
 */
final class RoleField
{
    /** @return list<string> trimmed, unique, non-empty, in the stored order; malformed entries dropped */
    public static function normalize(mixed $value): array
    {
        $out = [];
        foreach (self::strings($value) as $role) {
            $role = trim($role);
            if ($role !== '' && !in_array($role, $out, true)) {
                $out[] = $role;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (is_int($value)) {
            return [(string) $value];
        }
        if (is_array($value)) {
            $strings = [];
            foreach ($value as $role) {
                if (is_string($role) || is_int($role)) {
                    array_push($strings, ...self::strings($role));
                }
            }

            return $strings;
        }
        if (!is_string($value)) {
            return [];
        }
        $value = trim($value);
        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            $scalars = is_array($decoded) ? array_filter($decoded, static fn ($r): bool => is_string($r) || is_int($r)) : [];

            return array_map('strval', array_values($scalars));
        }

        return explode(',', $value);
    }
}
