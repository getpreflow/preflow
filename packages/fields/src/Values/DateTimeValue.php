<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * The value of a datetime field: one instant, stored in UTC as ISO 8601 text with a "Z"
 * ("2026-03-29T12:30:00Z"), which sorts correctly as text on every storage. People type and read it
 * in a zone: the field's "zone", else the site's (DateOptions::zone()).
 *
 * fromLocal() reads what is typed in a zone ("2026-03-29 14:30" or "2026-03-29T14:30", seconds
 * optional). Two moments a clock shows do not map to one instant:
 *
 * - a time that does not exist (the hour skipped when clocks go forward) is refused: fromLocal()
 *   gives null and localProblem() says 'gap';
 * - a time that happens twice (the hour repeated when clocks go back) is the earlier of the two
 *   instants (daylight saving time, before the change).
 *
 * @since 0.91.0
 */
final readonly class DateTimeValue
{
    /** The stored form. */
    public const string STORED = 'Y-m-d\TH:i:s\Z';

    private const string LOCAL = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/';

    private function __construct(
        private \DateTimeImmutable $instant,
    ) {}

    /** The instant a stored value holds; null for an empty value or text that is no date and time. A UTC "Z" or an offset is read. */
    public static function fromStored(mixed $stored): ?self
    {
        if (!is_string($stored)) {
            return null;
        }
        $text = trim($stored);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})$/', $text) !== 1) {
            return null;
        }
        // "2026-02-31T…" would roll over silently: the date must be a real one.
        if (!self::isCalendarDate(substr($text, 0, 10))) {
            return null;
        }
        try {
            $instant = new \DateTimeImmutable($text);
        } catch (\Exception) {
            return null;
        }

        return new self(new \DateTimeImmutable('@' . $instant->getTimestamp()));
    }

    /** Whether $stored is exactly the stored form: "Y-m-d\TH:i:s\Z", a real date and time. */
    public static function isStored(mixed $stored): bool
    {
        if (!is_string($stored) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $stored) !== 1) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat(self::STORED, $stored, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format(self::STORED) === $stored;
    }

    /** The instant of a time typed in $zone; null when it is no date and time, or does not exist there (see class). */
    public static function fromLocal(string $text, \DateTimeZone $zone): ?self
    {
        $parts = self::localParts($text);
        if ($parts === null) {
            return null;
        }
        $instants = self::instants($parts, $zone);

        return $instants === [] ? null : new self((new \DateTimeImmutable('@' . min($instants)))->setTimezone(new \DateTimeZone('UTC')));
    }

    /**
     * Why $text cannot be read as a time in $zone: 'gap' (well-formed, but the clocks skip it),
     * 'invalid' (no date and time), null when it reads fine.
     *
     * @return 'gap'|'invalid'|null
     */
    public static function localProblem(string $text, \DateTimeZone $zone): ?string
    {
        $parts = self::localParts($text);
        if ($parts === null) {
            return 'invalid';
        }

        return self::instants($parts, $zone) === [] ? 'gap' : null;
    }

    /** @return array{int, int, int, int, int, int}|null year, month, day, hour, minute, second */
    private static function localParts(string $text): ?array
    {
        if (preg_match(self::LOCAL, trim($text), $m) !== 1) {
            return null;
        }
        [$y, $mo, $d, $h, $mi, $s] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0)];
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }

        return [$y, $mo, $d, $h, $mi, $s];
    }

    /**
     * The instants (Unix seconds) at which $zone's clock shows the time: none in a gap, two in a
     * repeated hour, one otherwise. Tried: the offsets in force a day before and after.
     *
     * @param array{int, int, int, int, int, int} $parts
     * @return list<int>
     */
    private static function instants(array $parts, \DateTimeZone $zone): array
    {
        $naive = gmmktime($parts[3], $parts[4], $parts[5], $parts[1], $parts[2], $parts[0]);
        $offsets = [];
        foreach ([-86400, 0, 86400] as $shift) {
            $offsets[$zone->getOffset(new \DateTimeImmutable('@' . ($naive + $shift)))] = true;
        }
        $instants = [];
        foreach (array_keys($offsets) as $offset) {
            if ($zone->getOffset(new \DateTimeImmutable('@' . ($naive - $offset))) === $offset) {
                $instants[] = $naive - $offset;
            }
        }

        return $instants;
    }

    private static function isCalendarDate(string $date): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** The stored text: "Y-m-d\TH:i:s\Z" in UTC. */
    public function toStored(): string
    {
        return $this->instant->format(self::STORED);
    }

    /** The instant in $zone. */
    public function inZone(\DateTimeZone $zone): \DateTimeImmutable
    {
        return $this->instant->setTimezone($zone);
    }

    /** The time in $zone written with a PHP date() $format. */
    public function format(string $format, \DateTimeZone $zone): string
    {
        return $this->inZone($zone)->format($format);
    }

    /** The value of a datetime-local input in $zone: "Y-m-d\TH:i" (with ":s" only when there are seconds). */
    public function inputValue(\DateTimeZone $zone): string
    {
        $local = $this->inZone($zone);

        return $local->format($local->format('s') === '00' ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s');
    }

    public function timestamp(): int
    {
        return $this->instant->getTimestamp();
    }
}
