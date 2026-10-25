<?php

declare(strict_types=1);

namespace Preflow\Core\Cache;

/** Formats byte counts for humans. */
final class Bytes
{
    /** 0 B, 512 B, 1.5 KB, 3.1 MB: base 1024, one decimal above bytes. */
    public static function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return max(0, $bytes) . ' B';
        }

        $value = $bytes / 1024;
        foreach (['KB', 'MB', 'GB'] as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                return number_format($value, 1, '.', '') . ' ' . $unit;
            }
            $value /= 1024;
        }

        return '';
    }
}
