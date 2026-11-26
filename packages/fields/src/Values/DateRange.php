<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * The value of a daterange field: a start and an end, the end on or after the start. Stored as an
 * object (a JSON file holds it, SQL storage JSON text of it) with the keys start and end:
 *
 *     {"start": "2026-03-01", "end": "2026-03-07"}                          dates
 *     {"start": "2026-03-01T08:00:00Z", "end": "2026-03-07T16:00:00Z"}      with "with_time": date-times in UTC
 *
 * A date has no zone; a date-time is stored in UTC like a datetime field's and typed and read in the
 * field's zone. A range counts both ends: a stay from the 1st to the 7th includes the 7th.
 *
 * @since 0.91.0
 */
final readonly class DateRange
{
    /** @param string $start "Y-m-d", or "Y-m-d\TH:i:s\Z" */
    private function __construct(
        public string $start,
        public string $end,
    ) {}

    /** The range a stored value holds (the object, or JSON text of it); null for an empty value or one that is no valid range. */
    public static function fromStored(mixed $stored): ?self
    {
        $parts = self::parts($stored);
        if ($parts === null || $parts['start'] === null || $parts['end'] === null) {
            return null;
        }
        $start = self::point($parts['start']);
        $end = self::point($parts['end']);
        if ($start === null || $end === null || $start[1] !== $end[1] || $start[0] > $end[0]) {
            return null;
        }

        return new self($parts['start'], $parts['end']);
    }

    /**
     * The start and end of a stored or posted value, trimmed, null for a missing or empty one;
     * null for no value at all (both empty), and for something that is no object. Not checked:
     * the rule decides whether it is a range.
     *
     * @return array{start: ?string, end: ?string}|null
     */
    public static function parts(mixed $stored): ?array
    {
        $data = Link::decode($stored);
        if ($data === null) {
            return null;
        }
        $part = static fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $start = $part('start');
        $end = $part('end');

        return $start === null && $end === null ? null : ['start' => $start, 'end' => $end];
    }

    /**
     * An end of a range as an instant and whether it has a time: a date is midnight UTC of the
     * day (only compared with another date).
     *
     * @return array{int, bool}|null
     */
    public static function point(string $text): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? [(int) gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]), false] : null;
        }
        $value = DateTimeValue::isStored($text) ? DateTimeValue::fromStored($text) : null;

        return $value === null ? null : [$value->timestamp(), true];
    }

    /** @return array{start: string, end: string} */
    public function toStored(): array
    {
        return ['start' => $this->start, 'end' => $this->end];
    }

    /** Whether the ends have a time. */
    public function hasTime(): bool
    {
        return str_contains($this->start, 'T');
    }

    /**
     * An end written with a PHP date() $format in $zone (a date has no zone, and is the same day
     * everywhere); $format defaults to "Y-m-d", or "Y-m-d H:i" for a range with a time.
     */
    public function format(bool $end, \DateTimeZone $zone, ?string $format = null): string
    {
        $text = $end ? $this->end : $this->start;
        if ($this->hasTime()) {
            return (DateTimeValue::fromStored($text)?->format($format ?? 'Y-m-d H:i', $zone)) ?? '';
        }

        return (new \DateTimeImmutable($text . ' 00:00:00', new \DateTimeZone('UTC')))->format($format ?? 'Y-m-d');
    }
}
