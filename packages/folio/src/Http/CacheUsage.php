<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\CacheSource;

/**
 * What the registered caches hold right now, as the Cache screen and the dashboard's cache line both
 * read it: one row per cache with its raw files and bytes, and the totals. A cache that fails to
 * report is skipped, so one broken source never takes the screen down.
 */
final class CacheUsage
{
    /**
     * @return array{rows: list<array{source: CacheSource, entries: int, bytes: int, note: string}>, entries: int, bytes: int}|null null when the registry can't be read
     */
    public static function of(CacheRegistry $caches): ?array
    {
        try {
            $sources = $caches->all();
        } catch (\Throwable) {
            return null;
        }
        $rows = [];
        $entries = 0;
        $bytes = 0;
        foreach ($sources as $source) {
            try {
                $stats = $source->stats();
            } catch (\Throwable) {
                continue;
            }
            $row = ['source' => $source, 'entries' => max(0, $stats->entries), 'bytes' => max(0, $stats->bytes), 'note' => (string) $stats->note];
            $rows[] = $row;
            $entries += $row['entries'];
            $bytes += $row['bytes'];
        }

        return ['rows' => $rows, 'entries' => $entries, 'bytes' => $bytes];
    }

    /**
     * The caches that make up the bar: the largest ones (at most $max) holding at least $minPercent of
     * the bytes, biggest first, each with its share; `rest` is the share of everything else.
     *
     * @param list<array{source: CacheSource, entries: int, bytes: int, note: string}> $rows
     * @return array{segments: list<array{source: CacheSource, bytes: int, percent: float}>, rest: float, restBytes: int}
     */
    public static function segments(array $rows, int $total, int $max = 5, float $minPercent = 1.0): array
    {
        $segments = [];
        $shown = 0;
        if ($total > 0) {
            usort($rows, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
            foreach ($rows as $row) {
                $percent = $row['bytes'] / $total * 100;
                if (count($segments) >= $max || $percent < $minPercent) {
                    break;
                }
                $segments[] = ['source' => $row['source'], 'bytes' => $row['bytes'], 'percent' => $percent];
                $shown += $row['bytes'];
            }
        }

        return ['segments' => $segments, 'rest' => $total > 0 ? ($total - $shown) / $total * 100 : 0.0, 'restBytes' => $total - $shown];
    }
}
