<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Core\Activity\LastChanges;
use Preflow\Core\Context\Contexts;
use Preflow\Data\Attributes\Timestamps;

/**
 * The step "data.change-dates" of `preflow migrate` (@since 0.84.0): gives every record of a type
 * that has no change date yet (ChangeDates; records written before 0.84, or past the data layer)
 * the best one there is, on every storage:
 *
 * 1. the newest event the activity log keeps for it (a log with LastChanges, as the stored one);
 * 2. the type's own updated time (an `updatedAt` / `updated_at` value: read, never written);
 * 3. the time a published copy was written (its `_published_at`);
 * 4. when the storage last wrote it (WriteTimes: a JSON file's modification time);
 * 5. when it was created (a `createdAt` / `created_at` value);
 * 6. else the time of the migrate.
 *
 * Only empty change dates are written (null or ''), and nothing else, so a second run changes
 * nothing. Each value is written alone, past the data
 * layer's save (no version moves, nothing else is stamped). Records are read CHUNK at a time and
 * each chunk is written in one transaction where the storage has them, so a large table never
 * holds one long statement; a run cut short leaves the rest for the next migrate. A SQL table
 * without the column yet (its sync step comes first) is left out.
 *
 * The types: every record table the packages register (RecordTables) and every type of the
 * registry, each storage and table once.
 */
final class ChangeDateBackfill
{
    /** How many records are read and written at a time. */
    public const int CHUNK = 500;

    /**
     * @param \Closure(): list<TypeDefinition> $types the types whose records get dates
     * @param int $chunk how many records are read and written at a time
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly \Closure $types,
        private readonly ?LastChanges $activity = null,
        private readonly int $chunk = self::CHUNK,
    ) {
        if ($chunk < 1) {
            throw new \InvalidArgumentException('A backfill chunk holds at least one record.');
        }
    }

    /** @return list<string> one line per table that got dates */
    public function run(): array
    {
        $lines = [];
        $seen = [];
        foreach (($this->types)() as $def) {
            $place = $def->storage . '|' . $def->table;
            if (isset($seen[$place])) {
                continue;
            }
            $seen[$place] = true;
            $line = $this->backfill($def);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** The records of $def without a change date, given one; a line saying how many, or null for none. */
    private function backfill(TypeDefinition $def): ?string
    {
        try {
            if (!$this->dm->hasTable($def)) {
                return null;
            }
            $key = $this->dm->changeDateOf($def);
        } catch (\Throwable) {
            return null; // a storage that cannot be reached here is not this step's to fail
        }
        if ($key === null) {
            return null;
        }
        $driver = $this->dm->driver($def->storage);
        $now = Contexts::clock()->now();
        $counts = ['activity' => 0, 'updated' => 0, 'published' => 0, 'storage' => 0, 'created' => 0, 'now' => 0];
        $done = [];
        foreach ([null, ''] as $empty) {
            while (true) {
                $query = $empty === null ? (new Query())->where($key, null) : (new Query())->where($key, '=', $empty);
                $rows = $driver->findMany($def->table, $query->limit($this->chunk))->items();
                $rows = array_values(array_filter($rows, static fn (array $row): bool => !isset($done[(string) ($row[$def->idField] ?? '')])));
                if ($rows === []) {
                    break; // none left, or only rows a write did not take (never read twice)
                }
                $dates = $this->dates($def, $driver, $rows, $now, $counts);
                $write = static function () use ($driver, $def, $key, $dates): void {
                    foreach ($dates as $id => $value) {
                        $driver->save($def->table, (string) $id, [$key => $value], $def->idField);
                    }
                };
                $driver instanceof Transactions ? $this->dm->transaction([$def->storage], $write) : $write();
                foreach (array_keys($dates) as $id) {
                    $done[(string) $id] = true;
                }
                foreach ($rows as $row) {
                    $done[(string) ($row[$def->idField] ?? '')] = true;
                }
                if (count($rows) < $this->chunk) {
                    break;
                }
            }
        }
        $total = array_sum($counts);
        if ($total === 0) {
            return null;
        }
        $this->dm->reportWrite($def->storage, $def->table);
        $from = [];
        foreach (['activity' => 'the activity log', 'updated' => 'its updated time', 'published' => 'the publish time', 'storage' => 'the file time', 'created' => 'the creation time', 'now' => 'now'] as $source => $label) {
            if ($counts[$source] > 0) {
                $from[] = "{$counts[$source]} from {$label}";
            }
        }

        return "gave {$total} " . ($total === 1 ? 'record' : 'records') . " of {$def->table} a change date (" . implode(', ', $from) . ')';
    }

    /**
     * The change date of each row, as ChangeDates::text() writes it, counted by its source.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, int> $counts
     * @return array<string, string|int> id => the date
     */
    private function dates(TypeDefinition $def, StorageDriver $driver, array $rows, \DateTimeImmutable $now, array &$counts): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row[$def->idField] ?? null;
            if ((is_string($id) && $id !== '') || is_int($id)) {
                $ids[] = (string) $id;
            }
        }
        $logged = [];
        if ($this->activity !== null && $ids !== []) {
            try {
                $logged = $this->activity->lastChanged($def->key, $ids);
            } catch (\Throwable) {
                $logged = []; // a log that cannot answer: the next source
            }
        }
        $written = $driver instanceof WriteTimes && $ids !== [] ? $driver->writeTimes($def->table, $ids, $def->idField) : [];

        $dates = [];
        foreach ($rows as $row) {
            $id = $row[$def->idField] ?? null;
            if (!((is_string($id) && $id !== '') || is_int($id))) {
                continue;
            }
            $id = (string) $id;
            [$at, $source] = match (true) {
                ($t = ChangeDates::parse($logged[$id] ?? null)) !== null => [$t, 'activity'],
                ($t = self::first($row, Timestamps::UPDATED_NAMES)) !== null => [$t, 'updated'],
                ($t = ChangeDates::parse($row['_published_at'] ?? null)) !== null => [$t, 'published'],
                isset($written[$id]) => [$written[$id], 'storage'],
                ($t = self::first($row, Timestamps::CREATED_NAMES)) !== null => [$t, 'created'],
                default => [$now, 'now'],
            };
            $dates[$id] = ChangeDates::text($at);
            $counts[$source]++;
        }

        return $dates;
    }

    /**
     * The first readable time among $names in the row.
     *
     * @param array<string, mixed> $row
     * @param list<string> $names
     */
    private static function first(array $row, array $names): ?\DateTimeImmutable
    {
        foreach ($names as $name) {
            $at = ChangeDates::parse($row[$name] ?? null);
            if ($at !== null) {
                return $at;
            }
        }

        return null;
    }
}
