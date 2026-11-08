<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * A duration as templates write it: '30s', '30m', '12h', '2d', or a whole number of seconds
 * (an int, or digits alone).
 */
final class Duration
{
    private const array UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    /**
     * @return int seconds, 1 or more
     * @throws \InvalidArgumentException anything else (0, a negative, '1.5h', 'soon')
     */
    public static function seconds(int|string $duration): int
    {
        if (is_int($duration)) {
            $seconds = $duration;
        } elseif (preg_match('/^\s*(\d+)\s*([smhd]?)\s*$/i', $duration, $m) === 1) {
            $seconds = (int) $m[1] * self::UNITS[strtolower($m[2] === '' ? 's' : $m[2])];
        } else {
            throw new \InvalidArgumentException("'{$duration}' is not a duration: write it as 30m, 12h or 2d.");
        }
        if ($seconds < 1) {
            throw new \InvalidArgumentException('A duration is 1 second or more.');
        }

        return $seconds;
    }
}
