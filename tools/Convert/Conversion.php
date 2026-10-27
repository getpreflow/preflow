<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

/**
 * check → backup → convert → re-check. Any value that does not fit, or a failed backup, stops
 * before anything changed; a conversion that stops midway names the backup.
 */
final class Conversion
{
    public function __construct(
        private readonly Tables $tables,
        private readonly Backup $backup,
    ) {}

    /**
     * @param bool $checkOnly report only (--check)
     * @param \Closure(string): void $out one line at a time
     * @return int 0 converted, checked clean or nothing to do; 1 failures, a failed backup or a stopped conversion
     */
    public function run(bool $checkOnly, \Closure $out): int
    {
        $pending = $this->tables->pending();
        if ($pending === []) {
            $out('Nothing to convert: every record table is typed.');
            return 0;
        }
        foreach ($pending as $work) {
            $out('to convert ' . $work->describe());
        }

        $failures = (new Checker($this->tables))->check();
        if ($failures !== []) {
            foreach ($failures as $failure) {
                $out((string) $failure);
            }
            $out(count($failures) . ' value(s) do not fit. Nothing was changed.');
            return 1;
        }
        if ($checkOnly) {
            $out('Check passed: every value fits. Run without --check to convert.');
            return 0;
        }

        try {
            $path = $this->backup->create();
        } catch (\Throwable $e) {
            $out('Backup failed: ' . $e->getMessage() . ' Nothing was changed.');
            return 1;
        }
        $out("Backup: {$path}");

        try {
            (new Converter($this->tables))->convert($out);
            $left = $this->tables->pending();
            if ($left !== []) {
                throw new \RuntimeException('still not typed after converting: ' . implode('; ', array_map(static fn (Work $w): string => $w->describe(), $left)));
            }
        } catch (\Throwable $e) {
            $out('Conversion stopped: ' . $e->getMessage());
            $out("The tables converted so far stay converted; a rerun continues. Backup from before this run: {$path}");
            return 1;
        }
        $out('Done: every record table is typed.');

        return 0;
    }
}
