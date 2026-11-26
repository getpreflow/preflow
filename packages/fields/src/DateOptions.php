<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * The options of the date and time field types, top-level in the field's config:
 *
 *     "starts_at": {"type": "datetime", "zone": "Europe/Zurich"}
 *     "opening":   {"type": "time"}
 *     "stay":      {"type": "daterange", "with_time": true, "zone": "Europe/Zurich"}
 *
 * - "zone" (datetime, and daterange with "with_time"): the time zone people type and read the
 *   value in, a name such as "Europe/Zurich". Without it the site's zone is used (app.timezone,
 *   which Application sets as PHP's default zone), so a test or a site changes it in one place.
 *   The value itself is always stored in UTC.
 * - "with_time" (daterange): true for a range of date-times instead of dates.
 *
 * A "time" has no zone: it is a wall-clock time. None of these types is translatable.
 *
 * @since 0.91.0
 */
final readonly class DateOptions
{
    public function __construct(
        public ?string $zoneName = null,
        public bool $withTime = false,
    ) {}

    /** @param array<string, mixed> $config the field's config */
    public static function fromConfig(array $config): self
    {
        $zone = $config['zone'] ?? null;

        return new self(
            is_string($zone) && self::knownZone($zone) ? $zone : null,
            ($config['with_time'] ?? false) === true,
        );
    }

    /** The zone people type and read in: the field's "zone", else the site's. */
    public function zone(): \DateTimeZone
    {
        return new \DateTimeZone($this->zoneName ?? date_default_timezone_get());
    }

    private static function knownZone(string $name): bool
    {
        return in_array($name, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * @param array<string, mixed> $config the field's config (its "translatable" too)
     * @throws \LogicException
     */
    public static function check(string $type, array $config): void
    {
        FieldOptions::refuseTranslatable($type, $config);
        $own = match ($type) {
            'datetime' => ['zone'],
            'daterange' => ['zone', 'with_time'],
            default => [],
        };
        if ($own !== []) {
            FieldOptions::refuseNested($type, $config, $own);
        }
        if (array_key_exists('zone', $config)) {
            if ($type === 'time') {
                throw new \LogicException('A time field holds a time of day without a zone: remove "zone".');
            }
            if ($type === 'daterange' && ($config['with_time'] ?? false) !== true) {
                throw new \LogicException('"zone" belongs to a daterange of date-times: add "with_time": true, or remove "zone" (dates have no zone).');
            }
            if (!is_string($config['zone']) || !self::knownZone($config['zone'])) {
                throw new \LogicException('"zone" is ' . json_encode($config['zone'], JSON_UNESCAPED_SLASHES) . '; use a time zone name such as "Europe/Zurich" or "UTC", or leave it out for the site\'s zone (app.timezone).');
            }
        }
        if (array_key_exists('with_time', $config)) {
            if ($type !== 'daterange') {
                throw new \LogicException("\"with_time\" belongs to a daterange field; a {$type} field " . ($type === 'time' ? 'holds a time only' : 'always holds a date and a time') . '. Remove "with_time".');
            }
            if (!is_bool($config['with_time'])) {
                throw new \LogicException('"with_time" must be true or false.');
            }
        }
    }
}
