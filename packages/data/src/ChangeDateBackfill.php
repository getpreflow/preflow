<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Core\Activity\LastChanges;
use Preflow\Core\Context\Contexts;
use Preflow\Data\Attributes\Timestamps;

/**
 * The step "data.change-dates" of `preflow migrate`: gives every record of a type that has no
 * change date yet (ChangeDates; records written before 0.84, or past the data layer) the best one
 * there is, on every storage:
 *
 * 1. the newest event the activity log keeps for it (a log with LastChanges, as the stored one);
 * 2. the type's own updated time (an `updatedAt` / `updated_at` value: read, never written);
 * 3. the sources packages register (ChangeDateSources; preflow/publishing: a published copy's time);
 * 4. when the storage last wrote it (WriteTimes: a JSON file's modification time);
 * 5. when it was created (a `createdAt` / `created_at` value);
 * 6. else the time of the migrate.
 *
 * migrate runs while the site serves requests, so each date is a conditional write of that key
 * alone (DataManager::updateIf(): one `UPDATE … WHERE id = ? AND the date is empty` on SQL, a
 * check and write under the type's lock elsewhere): a record deleted meanwhile is not written back,
 * a date a save gave meanwhile stays, no version moves, and the record is written as a save writes
 * it (a JSON file in its canonical bytes). Only empty dates (null or '') are written, so a second
 * run changes nothing. Records are read CHUNK at a time, each chunk in one transaction where the
 * storage has them, in the order of their ids; a run cut short leaves the rest for the next migrate.
 *
 * What it says, one line per table: how many records got a date, from which sources; a warning for
 * a table that holds no change dates (no column: a table no package syncs, such as one a migration made); a "refused:" line (so
 * migrate exits 1) for a table it could not finish: a source that could not answer (the activity
 * log unreachable: no weaker source decides for those records; they are tried again next run), or a
 * failing read or write. The other tables go on.
 *
 * The types: every record table the packages register (RecordTables) and every type of the
 * registry, each storage and table once.
 *
 * @since 0.84.0
 */
final class ChangeDateBackfill
{
    /**
     * How many records are read and written at a time.
     *
     * @since 0.84.0
     */
    public const int CHUNK = 500;

    /**
     * @param \Closure(): list<TypeDefinition> $types the types whose records get dates
     * @param list<ChangeDateSource> $sources the registered sources (ChangeDateSources::all()), in order
     * @param int $chunk how many records are read and written at a time
     * @since 0.84.0
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly \Closure $types,
        private readonly ?LastChanges $activity = null,
        private readonly array $sources = [],
        private readonly int $chunk = self::CHUNK,
    ) {
        if ($chunk < 1) {
            throw new \InvalidArgumentException('A backfill chunk holds at least one record.');
        }
    }

    /**
     * @return list<string> per table: what it did ("gave …"), "warning: …" or "refused: …"
     * @since 0.84.0
     */
    public function run(): array
    {
        $lines = [];
        $seen = [];
        foreach (($this->types)() as $def) {
            $place = $def->storage . '|' . $def->table;
            if (isset($seen[$place]) || !$def->changeDates) {
                continue;
            }
            $seen[$place] = true;
            try {
                array_push($lines, ...$this->backfill($def));
            } catch (\Throwable $e) {
                $lines[] = "refused: {$def->table}: no change dates given ({$e->getMessage()}); run migrate again";
            }
        }

        return $lines;
    }

    /**
     * The records of $def without a change date, given one.
     *
     * @return list<string>
     */
    private function backfill(TypeDefinition $def): array
    {
        if (!$this->dm->hasTable($def)) {
            return []; // no table, no records
        }
        $driver = $this->dm->driver($def->storage);
        $key = $this->dm->changeDateOf($def);
        if ($key === null) {
            return $driver instanceof DescribesColumns
                ? ["warning: {$def->table} keeps no change dates: its table has no " . ChangeDates::FIELD . ' column (a table no package syncs, such as one a migration made: add the column there)']
                : []; // a storage that cannot say: DataManager keeps no dates on it
        }
        $now = Contexts::clock()->now();
        // The summary names the sources in the order they are asked.
        $counts = array_fill_keys([
            ...($this->activity !== null ? ['the activity log'] : []),
            'its updated time',
            ...array_map(static fn (ChangeDateSource $source): string => $source->label(), $this->sources),
            ...($driver instanceof WriteTimes ? ['the file time'] : []),
            'the creation time',
            'now',
        ], 0);
        $lines = [];
        try {
            foreach ([null, ''] as $empty) {
                // Paged by id: a record whose write does not take (it waits for the next run) is
                // passed over, never read again in this run, so it cannot hold up the rest.
                $after = null;
                while (true) {
                    $query = $empty === null ? (new Query())->where($key, null) : (new Query())->where($key, '=', $empty);
                    if ($after !== null) {
                        $query->where($def->idField, '>', $after);
                    }
                    $rows = $driver->findMany($def->table, $query->orderBy($def->idField)->limit($this->chunk))->items();
                    if ($rows === []) {
                        break;
                    }
                    $after = $rows[count($rows) - 1][$def->idField] ?? null;
                    [$dates, $labels] = $this->dates($def, $driver, $rows, $now);
                    $write = function () use ($def, $key, $dates, $labels, &$counts): void {
                        foreach ($dates as $id => $value) {
                            if ($this->writeIfEmpty($def, $key, (string) $id, $value)) {
                                $counts[$labels[$id]] = ($counts[$labels[$id]] ?? 0) + 1;
                            }
                        }
                    };
                    $driver instanceof Transactions ? $this->dm->transaction([$def->storage], $write) : $write();
                    if ($after === null || count($rows) < $this->chunk) {
                        break;
                    }
                }
            }
        } catch (ChangeDateSourceFailed $e) {
            $lines[] = "refused: {$def->table}: no change dates given ({$e->getMessage()}); nothing weaker decides, run migrate again";
        }

        $total = array_sum($counts);
        if ($total > 0) {
            $this->dm->reportWrite($def->storage, $def->table);
            $from = [];
            foreach (array_filter($counts) as $label => $count) {
                $from[] = "{$count} from {$label}";
            }
            array_unshift($lines, "gave {$total} " . ($total === 1 ? 'record' : 'records') . " of {$def->table} a change date (" . implode(', ', $from) . ')');
        }

        return $lines;
    }

    /** Writes $value as the record's change date while the record exists and holds none. */
    private function writeIfEmpty(TypeDefinition $def, string $key, string $id, string $value): bool
    {
        return $this->dm->updateIf(
            $def,
            $id,
            static fn (DynamicRecord $record): ?array => ChangeDates::isSet($record->get($key)) ? null : [$key => $value],
            static function (RunsSql $driver, TypeDefinition $def) use ($key, $id, $value): array {
                $q = $driver->dialect()->quoteIdentifier(...);

                return [
                    'UPDATE ' . $q($def->table) . ' SET ' . $q($key) . ' = ? WHERE ' . $q($def->idField) . ' = ? AND (' . $q($key) . ' IS NULL OR ' . $q($key) . " = '')",
                    [$value, $id],
                ];
            },
        );
    }

    /**
     * The change date of each row, as ChangeDates::text() writes it, and the label of its source.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: array<string, string>, 1: array<string, string>} id => the date, id => its source
     * @throws ChangeDateSourceFailed when a source cannot answer
     */
    private function dates(TypeDefinition $def, StorageDriver $driver, array $rows, \DateTimeImmutable $now): array
    {
        $usable = [];
        foreach ($rows as $row) {
            $id = $row[$def->idField] ?? null;
            if ((is_string($id) && $id !== '') || is_int($id)) {
                $usable[(string) $id] = $row;
            }
        }
        if ($usable === []) {
            return [[], []];
        }
        $ids = array_map(strval(...), array_keys($usable));

        // In order: the first source that knows a record decides its date.
        $known = [];
        $ask = static function (string $label, \Closure $answer) use (&$known): void {
            try {
                $known[] = [$label, $answer()];
            } catch (\Throwable $e) {
                throw new ChangeDateSourceFailed("{$label}: {$e->getMessage()}", 0, $e);
            }
        };
        if ($this->activity !== null) {
            $ask('the activity log', fn (): array => array_filter(array_map(ChangeDates::parse(...), $this->activity->lastChanged($def->key, $ids))));
        }
        $ask('its updated time', static fn (): array => array_filter(array_map(static fn (array $row): ?\DateTimeImmutable => self::first($row, Timestamps::UPDATED_NAMES), $usable)));
        foreach ($this->sources as $source) {
            $ask($source->label(), static fn (): array => $source->changedAt($def, array_values($usable)));
        }
        if ($driver instanceof WriteTimes) {
            $ask('the file time', static fn (): array => $driver->writeTimes($def->table, $ids, $def->idField));
        }
        $ask('the creation time', static fn (): array => array_filter(array_map(static fn (array $row): ?\DateTimeImmutable => self::first($row, Timestamps::CREATED_NAMES), $usable)));

        $dates = [];
        $labels = [];
        foreach (array_keys($usable) as $id) {
            $id = (string) $id;
            [$at, $label] = [$now, 'now'];
            foreach ($known as [$sourceLabel, $times]) {
                if (($times[$id] ?? null) instanceof \DateTimeInterface) {
                    [$at, $label] = [$times[$id], $sourceLabel];
                    break;
                }
            }
            $dates[$id] = ChangeDates::text($at);
            $labels[$id] = $label;
        }

        return [$dates, $labels];
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
