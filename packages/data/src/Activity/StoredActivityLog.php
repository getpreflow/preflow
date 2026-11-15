<?php

declare(strict_types=1);

namespace Preflow\Data\Activity;

use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Activity\ActivityPage;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * The activity log kept through the DataManager, in the system type preflow_activity (not in the
 * TypeRegistry), so it works on every storage driver: JSON, SQLite, MySQL and any other. Bound by
 * the core when the data layer boots; `preflow migrate` creates its table on SQL storage (the
 * step "activity.table"). It is stored on the storage alias "activity" when the site has one,
 * else on "default".
 *
 * Order: every row carries a sort key "seq", its time in UTC to the microsecond followed by the
 * row's id (fixed width, digits and lower-case hex around fixed letters), so one plain text
 * comparison orders by time, then id, the same on every driver. The cursor of a page is the last
 * row's key: a row recorded meanwhile sorts above it and never shifts a later page.
 *
 * Coalescing: a Saved by the same person (null counts as one) on the same subject and id, when
 * the newest row of that record is such a Saved at most COALESCE_SECONDS old, updates that row:
 * the changed fields merged (the row's first), the title and time moved, and so its key (it
 * moves to the top). Any other event in between starts a new row. The newest row of each record
 * is found through a second system type, preflow_activity_last (id: a hash of subject and id;
 * the row's id, kind, person and time), so a recording reads and writes single rows by id and
 * costs the same however long the log is, on every driver.
 *
 * Retention: on about 1 in PRUNE_EVERY recordings (never on a read), rows older than $keepDays
 * and those beyond the newest $keepRows are deleted, at most PRUNE_BATCH of each per run; 0
 * keeps everything (config/activity.php "keep_days", 90, and "keep_rows", 10000).
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

    public const int PRUNE_EVERY = 50;

    public const int PRUNE_BATCH = 1000;

    /** A cursor: as seq() writes it. */
    private const string SEQ = '/^\d{8}T\d{6}\.\d{6}Z[0-9a-f]{16}$/D';

    private readonly TypeDefinition $type;

    private readonly TypeDefinition $last;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @var \Closure(): bool whether this recording prunes */
    private readonly \Closure $pruneNow;

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     * @param \Closure(): bool|null $pruneNow asked once per recording; default: 1 in PRUNE_EVERY
     */
    public function __construct(
        private readonly DataManager $dm,
        string $storage = 'default',
        public readonly int $keepDays = self::KEEP_DAYS,
        public readonly int $keepRows = self::KEEP_ROWS,
        ?\Closure $clock = null,
        ?\Closure $pruneNow = null,
    ) {
        if ($keepDays < 0 || $keepRows < 0) {
            throw new \InvalidArgumentException('activity.keep_days and activity.keep_rows must be 0 (keep all) or more.');
        }
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
        $this->pruneNow = $pruneNow ?? static fn (): bool => random_int(1, self::PRUNE_EVERY) === 1;
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
                'row' => new TypeFieldDefinition('row', 'string'),
                'kind' => new TypeFieldDefinition('kind', 'string'),
                'actor' => new TypeFieldDefinition('actor', 'string'),
                'at' => new TypeFieldDefinition('at', 'string', index: true, length: 32),
            ],
            idField: 'id',
        );
    }

    /** The system type the rows are stored in. */
    public function definition(): TypeDefinition
    {
        return $this->type;
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
        $now = ($this->clock)();
        $at = self::parse($activity->at) ?? $now;

        $headId = hash('xxh128', $activity->subject . "\0" . $activity->id);
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
            'row' => (string) $row->getId(),
            'kind' => $activity->kind->value,
            'actor' => $activity->by,
            'at' => self::atom($at),
        ]), validate: false);

        if (($this->pruneNow)()) {
            $this->prune($now);
        }
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

        $rows = array_values($query->get()->items());
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
     * The row a Saved updates instead of adding one: the record's newest row ($head), when it is
     * a Saved by the same person at most COALESCE_SECONDS before $at and still kept.
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
        $row = $this->dm->findIn($this->type, (string) $head->get('row'));

        // A row of another record cannot be there (the head's id is the record's hash), but checked all the same.
        return $row !== null && (string) $row->get('subject') === $activity->subject && (string) $row->get('record_id') === $activity->id ? $row : null;
    }

    /**
     * Deletes what is older than keepDays and beyond the newest keepRows (PRUNE_BATCH of each),
     * rows and heads alike (a head whose row is gone only starts a new row).
     */
    private function prune(\DateTimeImmutable $now): void
    {
        if ($this->keepDays > 0) {
            $then = $now->modify("-{$this->keepDays} days");
            foreach ($this->dm->queryIn($this->type)->where('seq', '<', self::seq($then, ''))->limit(self::PRUNE_BATCH)->get()->items() as $row) {
                $this->dm->deleteIn($this->type, (string) $row->getId());
            }
            foreach ($this->dm->queryIn($this->last)->where('at', '<', self::atom($then))->limit(self::PRUNE_BATCH)->get()->items() as $head) {
                $this->dm->deleteIn($this->last, (string) $head->getId());
            }
        }
        if ($this->keepRows > 0) {
            foreach ([[$this->type, 'seq'], [$this->last, 'at']] as [$type, $order]) {
                $beyond = $this->dm->queryIn($type)->orderBy($order, SortDirection::Desc)->offset($this->keepRows)->limit(self::PRUNE_BATCH)->get()->items();
                foreach ($beyond as $row) {
                    $this->dm->deleteIn($type, (string) $row->getId());
                }
            }
        }
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
