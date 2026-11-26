<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * The value of a time field: a wall-clock time of day, stored as "HH:MM" (24 hours, "09:05"). It has
 * no date and no zone: "opens at 09:00" is 09:00 wherever it is read.
 *
 * parse() reads what an input gives ("9:05", "09:05", "09:05:30": seconds are dropped); fromStored()
 * reads exactly the stored form.
 *
 * @since 0.91.0
 */
final readonly class TimeOfDay
{
    private function __construct(
        public int $hour,
        public int $minute,
    ) {}

    public static function parse(mixed $text): ?self
    {
        if (!is_string($text) || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/', trim($text), $m) !== 1) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];

        return $hour <= 23 && $minute <= 59 ? new self($hour, $minute) : null;
    }

    /** The time a stored "HH:MM" holds; null for an empty value or anything else. */
    public static function fromStored(mixed $stored): ?self
    {
        return is_string($stored) && preg_match('/^\d{2}:\d{2}$/', $stored) === 1 ? self::parse($stored) : null;
    }

    public function toStored(): string
    {
        return sprintf('%02d:%02d', $this->hour, $this->minute);
    }

    /** The time written with a PHP date() $format ("g:i a" gives "9:05 am"). */
    public function format(string $format): string
    {
        return (new \DateTimeImmutable('1970-01-01 ' . $this->toStored() . ':00', new \DateTimeZone('UTC')))->format($format);
    }
}
