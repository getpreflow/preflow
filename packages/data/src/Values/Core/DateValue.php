<?php

declare(strict_types=1);

namespace Preflow\Data\Values\Core;

use Preflow\Data\HasStoredRules;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Validation\StoredDate;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * A "date" field's value, shape "date": stored and read out as given ("Y-m-d", "Y-m-d\TH:i" with
 * optional seconds, or ISO 8601 with an offset that server code stored); an empty one ("") is
 * stored as given and read out as null.
 * Nothing is reformatted: a value the type cannot read passes through and its stored rule
 * (StoredDate, storedRules()) refuses it on save.
 *
 * @since 0.93.0
 */
final class DateValue implements FieldValue, DescribesShapes, HasStoredRules
{
    /** As given: "" stays "" (as Folio 0.92 stored it in a block and a translatable field; a record-level field's "" is null in storageValues()). */
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return $input;
    }

    /** The stored text when read() reads it; null without a value and for anything else stored ("22.10.2026", a number, a list). */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?string
    {
        return is_string($stored) && self::read($stored) !== null ? $stored : null;
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'date';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('date');
    }

    /** What every write checks: a value read() reads, with or without "time". */
    public function storedRules(array $config): array
    {
        return [new StoredDate()];
    }

    /**
     * The date a stored value holds, in the app timezone, or null when the type does not read it.
     * It reads exactly "Y-m-d", "Y-m-d\TH:i" and "Y-m-d\TH:i:s" (what the inputs post; a real
     * calendar date and time, in the app timezone) and ISO 8601 with an offset ("Y-m-d\TH:i:sP",
     * "Y-m-d\TH:i:s.uP", "Y-m-d\TH:iP"), also a real date and time (no overflow:
     * "2026-02-30T05:00:00+00:00" or an hour 25 is not read). Nothing around the value: a padded
     * one (" 2026-11-27 ") is not read.
     *
     * Moved from Folio's DateFieldType::read() (0.92.1), which delegates here.
     */
    public static function read(string $value): ?\DateTimeImmutable
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        foreach (['Y-m-d|' => 'Y-m-d', 'Y-m-d\TH:i|' => 'Y-m-d\TH:i', 'Y-m-d\TH:i:s|' => 'Y-m-d\TH:i:s'] as $format => $shape) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, $zone);
            if ($date !== false && $date->format($shape) === $value) {
                return $date;
            }
        }
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:iP'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $problems = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return $date->setTimezone($zone);
            }
        }

        return null;
    }
}
