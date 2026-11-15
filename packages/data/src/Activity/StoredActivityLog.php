<?php

declare(strict_types=1);

namespace Preflow\Data\Activity;

use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Activity\ActivityPage;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\ExclusiveWrites;
use Preflow\Data\Savepoints;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Psr\Log\LoggerInterface;

/**
 * The activity log kept through the DataManager, in two system types (not in the TypeRegistry),
 * so it works on every storage driver: JSON, SQLite, MySQL and any other. Bound by the core when
 * the data layer boots; `preflow migrate` creates its tables on SQL storage (the step
 * "activity.table"). It is stored on the storage alias "activity" when the site has one, else on
 * "default".
 *
 * - preflow_activity: the rows. Index (subject, record_id) for forget(), index on seq for order.
 * - preflow_activity_last: each record's newest row (id: a hash of subject and id; the row's id,
 *   kind, person and time), so record() reads and writes single rows by id and costs the same
 *   however long the log is, on every driver.
 *
 * Order: every row carries a sort key "seq", its time in UTC to the microsecond followed by the
 * row's id (fixed width, digits and lower-case hex around fixed letters), so one plain text
 * comparison orders by time, then id, the same on every driver. The cursor of a page is the last
 * row's key: a row recorded meanwhile sorts above it and never shifts a later page. A row that
 * coalesces gets a new key: it moves to the top and leaves the later pages.
 *
 * Coalescing (one row per editing session): a Saved by the same person (null counts as one) on
 * the same record updates the record's newest row when that row is such a Saved and its last save
 * was at most COALESCE_SECONDS ago: the changed fields merged (the row's first), the title and
 * time moved. The window slides: each save restarts it, so the row stays open while the person
 * keeps saving and closes after 10 minutes without a save. Any other event in between starts a
 * new row. The read and write run inside ExclusiveWrites::exclusive() where the storage offers
 * it (the built-in drivers do), so two concurrent saves never both start a row; a storage
 * without it may, rarely, get two rows for one session.
 *
 * Transactions: on a storage with Savepoints (SQL), a recording inside a caller's open
 * transaction runs under a savepoint: its rows become visible when the caller commits and are
 * dropped when it rolls back, and a failing recording leaves the caller's transaction usable.
 *
 * Retention: prune() (`php preflow activity:prune`, also run by `members:prune`; never record()
 * or recent()) deletes rows older than $keepDays and beyond the newest $keepRows, in batches;
 * 0 keeps everything (config/activity.php "keep_days", 90, and "keep_rows", 10000).
 *
 * Not migrated yet (a SQL site without the tables): recent() reads an empty log, prune() and
 * forget() do nothing, and the logger is told once; record() throws (its callers catch and log).
 *
 * @since 0.77
 */
final class StoredActivityLog implements ActivityLog
{
    public const string TABLE = 'preflow_activity';

    /** The newest row of each record (coalescing). */
    public const string LAST_TABLE = 'preflow_activity_last';

    /** The storage alias the log uses when a site configures it (config/data.php "aliases"). */
    public const string STORAGE = 'activity';

    public const int KEEP_DAYS = 90;

    public const int KEEP_ROWS = 10000;

    public const int COALESCE_SECONDS = 600;

    /** How many rows prune() and forget() read and delete at a time. */
    public const int BATCH = 1000;

    /** A cursor: as seq() writes it. */
    private const string SEQ = '/^\d{8}T\d{6}\.\d{6}Z[0-9a-f]{16}$/D';

    private readonly TypeDefinition $type;

    private readonly TypeDefinition $last;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    private bool $missingTold = false;

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     * @param LoggerInterface|null $logger told (once) that the tables are missing; else error_log()
     */
    public function __construct(
        private readonly DataManager $dm,
        string $storage = 'default',
        public readonly int $keepDays = self::KEEP_DAYS,
        public readonly int $keepRows = self::KEEP_ROWS,
        ?\Closure $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($keepDays < 0 || $keepRows < 0) {
            throw new \InvalidArgumentException('activity.keep_days and activity.keep_rows must be 0 (keep all) or more.');
        }
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
        $this->type = new TypeDefinition(
            key: self::TABLE,
            table: self::TABLE,
            storage: $storage,
            fields: [
                'subject' => new TypeFieldDefinition('subject', 'string', length: 64),
                'record_id' => new TypeFieldDefinition('record_id', 'string', length: 191),
                'title' => new TypeFieldDefinition('title', 'text'),
                'kind' => new TypeFieldDefinition('kind', 'string'),
                'changed' => new TypeFieldDefinition('changed', 'text'),
                'actor' => new TypeFieldDefinition('actor', 'string'),
                'at' => new TypeFieldDefinition('at', 'string'),
                'seq' => new TypeFieldDefinition('seq', 'string', index: true, length: 48),
            ],
            idField: 'id',
            indexes: [['subject', 'record_id']],
        );
        $this->last = new TypeDefinition(
            key: self::LAST_TABLE,
            table: self::LAST_TABLE,
            storage: $storage,
            fields: [
                'row_id' => new TypeFieldDefinition('row_id', 'string', index: true, length: 16),
                'kind' => new TypeFieldDefinition('kind', 'string'),
                'actor' => new TypeFieldDefinition('actor', 'string'),
                'at' => new TypeFieldDefinition('at', 'string'),
            ],
            idField: 'id',
        );
    }

    /** The system type the rows are stored in. */
    public function definition(): TypeDefinition
    {
        return $this->type;
    }

    /** The system type of each record's newest row. */
    public function lastDefinition(): TypeDefinition
    {
        return $this->last;
    }

    /**
     * Its tables, for `preflow migrate` (RecordTableSync) and conversions (RecordTables).
     *
     * @return list<array{def: TypeDefinition, extra: list<string>, typed: bool, unique: bool}>
     */
    public function tables(): array
    {
        return [RecordTables::entry($this->type), RecordTables::entry($this->last)];
    }

    public function record(Activity $activity): void
    {
        $at = self::parse($activity->at) ?? ($this->clock)();
        $headId = self::headId($activity->subject, $activity->id);

        $this->guarded(fn () => $this->exclusive(function () use ($activity, $at, $headId): void {
            $head = $this->dm->findIn($this->last, $headId);
            $row = $activity->kind === ActivityKind::Saved && $head !== null ? $this->coalescible($head, $activity, $at) : null;
            if ($row !== null) {
                $changed = self::changed($row->get('changed'));
                foreach ($activity->changed as $field) {
                    if (!in_array($field, $changed, true)) {
                        $changed[] = $field;
                    }
                }
                $row->set('title', $activity->title);
                $row->set('changed', json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $row->set('at', self::atom($at));
                $row->set('seq', self::seq($at, (string) $row->getId()));
            } else {
                $id = bin2hex(random_bytes(8));
                $row = new DynamicRecord($this->type, [
                    'id' => $id,
                    'subject' => $activity->subject,
                    'record_id' => $activity->id,
                    'title' => $activity->title,
                    'kind' => $activity->kind->value,
                    'changed' => json_encode(array_values($activity->changed), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'actor' => $activity->by,
                    'at' => self::atom($at),
                    'seq' => self::seq($at, $id),
                ]);
            }
            $this->dm->saveType($row, validate: false);
            $this->dm->saveType(new DynamicRecord($this->last, [
                'id' => $headId,
                'row_id' => (string) $row->getId(),
                'kind' => $activity->kind->value,
                'actor' => $activity->by,
                'at' => self::atom($at),
            ]), validate: false);
        }));
    }

    public function recent(int $limit, ?array $subjects = null, ?string $before = null): ActivityPage
    {
        if ($limit < 1 || $subjects === []) {
            return new ActivityPage();
        }
        $query = $this->dm->queryIn($this->type)->orderBy('seq', SortDirection::Desc)->limit($limit + 1);
        if ($subjects !== null) {
            $query->where('subject', 'IN', array_values(array_unique(array_map(strval(...), $subjects))));
        }
        if ($before !== null && preg_match(self::SEQ, $before) === 1) {
            $query->where('seq', '<', $before);
        }

        $rows = $this->whenStored(static fn (): array => array_values($query->get()->items()), []);
        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $next = (string) $rows[$limit - 1]->get('seq');
        }
        $items = [];
        foreach ($rows as $row) {
            $kind = ActivityKind::tryFrom((string) $row->get('kind'));
            if ($kind === null) {
                continue; // a kind of a later version: left out, the cursor still counts it
            }
            $by = $row->get('actor');
            $items[] = new Activity(
                (string) $row->get('subject'),
                (string) $row->get('record_id'),
                (string) $row->get('title'),
                $kind,
                self::changed($row->get('changed')),
                is_string($by) && $by !== '' ? $by : null,
                (string) $row->get('at'),
            );
        }

        return new ActivityPage($items, $next);
    }

    /**
     * Deletes the rows older than keepDays and beyond the newest keepRows, BATCH at a time, and
     * the newest-row entries that pointed at them. Returns how many rows went.
     */
    public function prune(): int
    {
        return $this->whenStored($this->pruneStored(...), 0);
    }

    private function pruneStored(): int
    {
        $deleted = 0;
        if ($this->keepDays > 0) {
            $cutoff = self::seq(($this->clock)()->modify("-{$this->keepDays} days"), '');
            do {
                $ids = $this->ids($this->dm->queryIn($this->type)->where('seq', '<', $cutoff)->limit(self::BATCH));
                $deleted += $this->deleteRows($ids);
            } while (count($ids) === self::BATCH);
        }
        if ($this->keepRows > 0) {
            do {
                $ids = $this->ids($this->dm->queryIn($this->type)->orderBy('seq', SortDirection::Desc)->offset($this->keepRows)->limit(self::BATCH));
                $deleted += $this->deleteRows($ids);
            } while ($ids !== []);
        }

        return $deleted;
    }

    public function forget(string $subject, string $id): void
    {
        $this->whenStored(fn () => $this->exclusive(function () use ($subject, $id): void {
            do {
                $rows = $this->dm->queryIn($this->type)->where('subject', $subject)->where('record_id', $id)->limit(self::BATCH)->get()->items();
                // A text column may compare case- or accent-insensitively (MySQL): exact matches only.
                $exact = array_values(array_filter($rows, static fn (DynamicRecord $r): bool => (string) $r->get('subject') === $subject && (string) $r->get('record_id') === $id));
                $this->dm->deleteManyIn($this->type, array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $exact));
            } while ($exact !== [] && count($rows) === self::BATCH);
            $this->dm->deleteIn($this->last, self::headId($subject, $id));
        }), null);
    }

    /**
     * $fn's result; while the log's table is missing (a SQL site not migrated yet) $empty, and
     * the logger is told once. Any other failure is thrown.
     *
     * @template T
     * @param \Closure(): T $fn
     * @param T $empty
     * @return T
     */
    private function whenStored(\Closure $fn, mixed $empty): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            if ($this->dm->hasTable($this->type)) {
                throw $e;
            }
            $this->tellMissing();

            return $empty;
        }
    }

    /**
     * Deletes these rows and the newest-row entries pointing at them (their record then starts a
     * new row). @param list<string> $ids
     */
    private function deleteRows(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $this->exclusive(function () use ($ids): void {
            $heads = $this->ids($this->dm->queryIn($this->last)->where('row_id', 'IN', $ids));
            $this->dm->deleteManyIn($this->type, $ids);
            $this->dm->deleteManyIn($this->last, $heads);
        });

        return count($ids);
    }

    /** @return list<string> */
    private function ids(\Preflow\Data\QueryBuilder $query): array
    {
        return array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), array_values($query->get()->items()));
    }

    /**
     * The row a Saved updates instead of adding one: the record's newest row ($head), when it is
     * a Saved by the same person whose last save was at most COALESCE_SECONDS before $at (a
     * sliding window), and still kept.
     */
    private function coalescible(DynamicRecord $head, Activity $activity, \DateTimeImmutable $at): ?DynamicRecord
    {
        $by = $head->get('actor');
        $by = is_string($by) && $by !== '' ? $by : null;
        $last = self::parse((string) $head->get('at'));
        $fresh = $last !== null && $at->getTimestamp() - $last->getTimestamp() <= self::COALESCE_SECONDS;
        if ($head->get('kind') !== ActivityKind::Saved->value || $by !== $activity->by || !$fresh) {
            return null;
        }
        $row = $this->dm->findIn($this->type, (string) $head->get('row_id'));

        // The head's id is the record's hash, so its row is the record's; checked all the same.
        return $row !== null && (string) $row->get('subject') === $activity->subject && (string) $row->get('record_id') === $activity->id ? $row : null;
    }

    /** $fn under a savepoint when the storage has them (see the class). */
    private function guarded(\Closure $fn): void
    {
        $driver = $this->dm->driver($this->type->storage);
        $driver instanceof Savepoints ? $driver->savepoint($fn) : $fn();
    }

    /** $fn holding the log's lock when the storage offers one (see the class). */
    private function exclusive(\Closure $fn): void
    {
        $driver = $this->dm->driver($this->last->storage);
        $driver instanceof ExclusiveWrites ? $driver->exclusive(self::LAST_TABLE, $fn) : $fn();
    }

    private function tellMissing(): void
    {
        if ($this->missingTold) {
            return;
        }
        $this->missingTold = true;
        $message = 'Activity log: the table ' . self::TABLE . ' is missing; run `php preflow migrate`.';
        $this->logger !== null ? $this->logger->warning($message) : error_log($message);
    }

    private static function headId(string $subject, string $id): string
    {
        return hash('xxh128', $subject . "\0" . $id);
    }

    /** The sort key: the UTC time to the microsecond, then the row id. */
    private static function seq(\DateTimeImmutable $at, string $id): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis.u') . 'Z' . $id;
    }

    private static function atom(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private static function parse(string $at): ?\DateTimeImmutable
    {
        if ($at === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($at);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return list<string> */
    private static function changed(mixed $stored): array
    {
        $changed = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($changed) ? array_values(array_filter($changed, 'is_string')) : [];
    }
}
