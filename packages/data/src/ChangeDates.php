<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Attributes\Timestamps;
use Preflow\Data\Schema\ColumnKind;

/**
 * Every record of a type keeps when it was last changed: its change date (@since 0.84.0).
 *
 * The key: a type that declares an updated field of its own (Timestamps::UPDATED_NAMES:
 * `updatedAt`, else `updated_at`) keeps its change date there, so no record holds it twice; every
 * other type in the system key FIELD (`_updated_at`, beside `_version` and `_published_at`).
 *
 * The value: the time in UTC as ISO 8601 text to the second ("2026-11-22T18:59:29+00:00",
 * DATE_ATOM), so a plain text comparison orders records by it on every driver; a declared field
 * stored as a number gets the Unix time instead.
 *
 * Where it is written: DataManager sets it on every save of a type's record that changes it
 * (saveType(), saveTypeIfVersion(), and save()/insert()/insertIgnoring()/insertMany()/update() of a
 * class-backed type), from the render's clock (Contexts::clock()); a save that changes nothing
 * keeps the stored date (a versioned save: the date moves with the version), and it never counts
 * as content. keepingChangeDates() keeps the date a record carries (an import, a published copy's
 * declared field). Writes that name their fields write only
 * those and leave it as stored: updateIf(), updateFieldsIfVersion(), incrementIn(), raw SQL and a
 * driver called directly (a caller that wants it moved includes it, as of(): $def => now()).
 * A read never changes it. Plain models (no type) keep #[Timestamps] for this.
 *
 * Storage: a JSON record holds the key like any other; on SQL, `preflow migrate` gives each table
 * it makes a column for it (RecordTableSync, kind date: text compared exactly), and DataManager
 * writes it only where the table has it (DataManager::hasColumn()), so a site saves as before
 * until its migrate ran. A storage driver needs nothing new: it stores the key as it stores the
 * fields. The step "data.change-dates" (ChangeDateBackfill) gives the records written before a date.
 */
final class ChangeDates
{
    /** The system key of the change date, for a type without an updated field of its own. */
    public const string FIELD = '_updated_at';

    /** The key the records of $def keep their change date in: the declared updated field, else FIELD. */
    public static function of(TypeDefinition $def): string
    {
        return self::declared($def) ?? self::FIELD;
    }

    /** The updated field $def declares (Timestamps::UPDATED_NAMES, the first it has), or null. */
    public static function declared(TypeDefinition $def): ?string
    {
        foreach (Timestamps::UPDATED_NAMES as $name) {
            if (isset($def->fields[$name])) {
                return $name;
            }
        }

        return null;
    }

    /** $at as a change date of $def is stored: ISO 8601 in UTC, or the Unix time for a declared number field. */
    public static function value(TypeDefinition $def, \DateTimeInterface $at, ?FieldKinds $kinds = null): string|int
    {
        $field = self::declared($def);
        if ($field !== null && ($kinds ?? new FieldKinds())->columnKindOf($def->fields[$field]) === ColumnKind::Number) {
            return $at->getTimestamp();
        }

        return self::text($at);
    }

    /** $at as ISO 8601 text in UTC to the second (DATE_ATOM): the form FIELD holds. */
    public static function text(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    /**
     * A stored change date as a time: ISO 8601 text (any offset, with or without seconds or a
     * fraction), "Y-m-d H:i:s" text (read in the default time zone), a Unix time or a date
     * object; null for an empty or unreadable value.
     */
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value) || (is_string($value) && preg_match('/^\d{9,11}$/D', $value) === 1)) {
            return (int) $value > 0 ? (new \DateTimeImmutable('@' . (int) $value)) : null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', trim($value)) !== 1) {
            return null;
        }
        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }

    /** Whether $value is a change date at all (not null, '', 0). */
    public static function isSet(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== 0 && $value !== '0';
    }
}
