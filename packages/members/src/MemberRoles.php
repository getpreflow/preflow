<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\DynamicRecord;

/**
 * A member's roles field (pending, member or rejected) as it may arrive: an array, a JSON array
 * string (as SQL returns a stored list), a comma-separated string, or nothing.
 */
final class MemberRoles
{
    /** @return list<string> trimmed, unique, non-empty, in the stored order; malformed entries dropped */
    public static function of(DynamicRecord $member): array
    {
        return self::normalize($member->get('roles'));
    }

    /** @return list<string> */
    public static function normalize(mixed $roles): array
    {
        $out = [];
        foreach (self::strings($roles) as $role) {
            $role = trim($role);
            if ($role !== '' && !in_array($role, $out, true)) {
                $out[] = $role;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function strings(mixed $roles): array
    {
        if (is_int($roles)) {
            return [(string) $roles];
        }
        if (is_array($roles)) {
            // Each entry may itself be a JSON string (a stored list cast with (array)).
            $strings = [];
            foreach ($roles as $role) {
                if (is_string($role) || is_int($role)) {
                    array_push($strings, ...self::strings($role));
                }
            }

            return $strings;
        }
        if (!is_string($roles)) {
            return [];
        }
        $roles = trim($roles);
        if (str_starts_with($roles, '[')) {
            $decoded = json_decode($roles, true);
            $scalars = is_array($decoded) ? array_filter($decoded, static fn ($r): bool => is_string($r) || is_int($r)) : [];

            return array_map('strval', array_values($scalars));
        }

        return explode(',', $roles);
    }
}
