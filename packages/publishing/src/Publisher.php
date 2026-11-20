<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\WritePolicy;
use Preflow\Core\Context\Writes;
use Preflow\Data\AggregatingStorage;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;
use Psr\Log\LoggerInterface;

/**
 * Working copy (what the admin edits, the type's own table) and published
 * snapshot (what the public site reads, {table}__published). States are
 * derived by comparing content; system keys in META never count as content. A snapshot keeps the
 * working `_version` it was published from (`_published_version`, @since 0.77), so states() and
 * stateCounts() compare two versions instead of the content (state() still compares the content).
 *
 * What an app adds comes in through interfaces (the provider binds a default for each the app
 * leaves open): which types publish (PublishingTypes), the rules a publish checks
 * (RecordChecks), the languages a record must be complete in (LanguageChecks), an index that
 * follows each save (RecordIndex), the record's public addresses (RecordAddresses) and the
 * columns the app writes besides the fields (RecordColumns: a restore clears those an old
 * revision lacks).
 *
 * The activity log (@since 0.77) hears of each publish (Published, with the fields that differ
 * from the previous snapshot: those of the revision), unpublish (Unpublished), restore
 * (Restored) and discard (Discarded), with who did it. A failing log is logged as a warning and never fails the write.
 */
final class Publisher
{
    /** The system keys every table of a publishing type has (working copies, snapshots, revisions). @since 0.77 */
    public const array TABLE_META = ['_version', '_publish_at', '_share_token', '_published_at', '_published_by', '_order'];

    /** A snapshot's key (only snapshots have it): the working `_version` it was published from. @since 0.77 */
    public const string PUBLISHED_VERSION = '_published_version';

    /** System keys, never content: TABLE_META and PUBLISHED_VERSION (@since 0.77). */
    public const array META = [...self::TABLE_META, self::PUBLISHED_VERSION];

    /** Working-copy keys that are not content and are written without a new version (saveSystemKeys()). */
    private const array SYSTEM_KEYS = ['_order', '_share_token'];

    /** Working-copy-only keys that are not copied into a snapshot. */
    private const array WORKING_ONLY = ['_version', '_publish_at', '_share_token'];

    /** How often saveDraft() without a loaded version tries before giving up on a busy record. */
    private const int SAVE_ATTEMPTS = 3;

    /** How many ids states() reads per query. */
    private const int STATES_CHUNK = 500;

    /**
     * The time of publish stamps and revisions. By default (since 0.81) the current render's clock
     * (Contexts::clock()), and the system clock outside every render.
     *
     * @var \Closure(): \DateTimeImmutable
     */
    private readonly \Closure $now;

    public function __construct(
        private readonly DataManager $dm,
        private readonly PublishingTypes $types,
        private readonly RecordChecks $checks,
        private readonly ?int $keepRevisions = 20,
        ?\Closure $now = null,
        private readonly ?LanguageChecks $languages = null,
        private readonly bool $completeLanguages = false,
        private readonly ?RecordIndex $index = null,
        private readonly ?RecordAddresses $addresses = null,
        private readonly ?RecordColumns $columns = null,
        ?ActivityLog $activity = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($keepRevisions !== null && $keepRevisions < 0) {
            throw new \InvalidArgumentException('publishing.revisions must be 0 (keep all) or more.');
        }
        $this->now = $now ?? static fn (): \DateTimeImmutable => Contexts::clock()->now();
        $this->activity = new ActivityNotes($activity, $logger);
        $this->logger = $logger;
    }

    private readonly ?LoggerInterface $logger;

    private readonly ActivityNotes $activity;

    public function enabled(string $type): bool
    {
        return $this->types->publishes($type);
    }

    public function snapshot(string $type, string $id): ?DynamicRecord
    {
        return $this->dm->findIn(ShadowTypes::published($this->definition($type)), $id);
    }

    /** What the public site sees: the snapshot when publishing, else the record itself. */
    public function live(string $type, string $id): ?DynamicRecord
    {
        return $this->enabled($type) ? $this->snapshot($type, $id) : $this->dm->findType($type, $id);
    }

    /** Public listing queries: snapshots when publishing, else the type's records. */
    public function query(string $type): QueryBuilder
    {
        return $this->enabled($type)
            ? $this->dm->queryIn(ShadowTypes::published($this->definition($type)))
            : $this->dm->queryType($type);
    }

    public function state(DynamicRecord $working): PublishState
    {
        $id = (string) $working->getId();

        return $this->stateWith($working, $this->dm->findIn(ShadowTypes::published($working->getType()), $id));
    }

    /**
     * The state of a working copy against a snapshot the caller already read (null: none is
     * published), without reading anything: what state() returns for the same pair.
     */
    public function stateWith(DynamicRecord $working, ?DynamicRecord $snapshot): PublishState
    {
        return self::compare($working, $snapshot);
    }

    /**
     * What a publish of the working copy would change: the top-level keys whose working value
     * differs from the published one. First the type's fields that differ, in field order; then
     * the other content keys a record carries besides its fields (its languages "_locales", its
     * SEO "_seo", translation markers…), sorted, so an edit to those alone is named too. The id
     * and META never count, nor does an empty value against a missing one. Both sides are
     * compared as stored (comparable(): JSON text decoded, numbers and booleans as SQL returns
     * them), so the working copy may be the stored one or one built in PHP. Empty for a record
     * never published, which has nothing to compare with. Reads the snapshot once.
     *
     * @since 0.75
     *
     * @return list<string>
     */
    public function changes(DynamicRecord $working): array
    {
        $def = $working->getType();
        $snapshot = $this->dm->findIn(ShadowTypes::published($def), (string) $working->getId());
        if ($snapshot === null) {
            return [];
        }
        $now = self::normalized($working);
        $before = self::normalized($snapshot);
        $others = array_diff(array_unique([...array_keys($now), ...array_keys($before)]), array_keys($def->fields));
        sort($others);

        $changed = [];
        foreach ([...array_keys($def->fields), ...$others] as $key) {
            if ($key === $def->idField) {
                continue;
            }
            if (json_encode(self::sortKeys($now[$key] ?? null)) !== json_encode(self::sortKeys($before[$key] ?? null))) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * States of a type's working copies: every working copy, or the ones with these ids (ids
     * that do not exist are left out; two queries per 500 ids).
     *
     * From the versions (@since 0.77): reads only the working copies' `_version` and the
     * snapshots' `_published_version` (an aggregate query on storage that groups,
     * AggregatingStorage; the records otherwise). No snapshot is Draft, the version it was
     * published from is Published, another is Changed. A draft save whose content equals the
     * snapshot's (an edit undone by hand) marks the snapshot with its version, so both agree there;
     * a write past Publisher that changes content keeping the version is out of their reach. A snapshot without the key (published
     * before 0.77) is compared in full, as state() does, until it is published again.
     *
     * @param list<string>|null $ids
     * @return array<string, PublishState> keyed by id
     */
    public function states(string $type, ?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }
        $def = $this->definition($type);
        $shadow = ShadowTypes::published($def);
        $only = $ids === null ? null : array_values(array_unique(array_map('strval', $ids)));
        // A bounded IN list per query (MySQL binds one placeholder per id).
        $chunks = $only === null ? [null] : array_chunk($only, self::STATES_CHUNK);

        // Tables not migrated yet (no column for it): every record compared in full, as before 0.77.
        if (!$this->dm->hasColumn($shadow, self::PUBLISHED_VERSION)) {
            return $this->compared($def, $only);
        }

        $states = [];
        $legacy = [];
        foreach ($chunks as $chunk) {
            $published = $this->versions($shadow, self::PUBLISHED_VERSION, $chunk);
            foreach ($this->versions($def, '_version', $chunk) as $id => $version) {
                $id = (string) $id;
                // An IN on a text column compares case- and accent-insensitively on MySQL: exact ids only.
                if ($chunk !== null && !in_array($id, $chunk, true)) {
                    continue;
                }
                if (!array_key_exists($id, $published)) {
                    $states[$id] = PublishState::Draft;
                } elseif ($published[$id] === null) {
                    $legacy[] = $id;
                    $states[$id] = PublishState::Changed; // replaced below, keeps the order
                } else {
                    $states[$id] = $published[$id] === ($version ?? 0) ? PublishState::Published : PublishState::Changed;
                }
            }
        }

        return $legacy === [] ? $states : array_replace($states, $this->compared($def, $legacy));
    }

    /**
     * How many working copies of the type are in each state, from states() (the versions): every
     * PublishState value as a key, 0 when none is.
     *
     * @since 0.77
     *
     * @return array<string, int> keyed by PublishState value
     */
    public function stateCounts(string $type): array
    {
        $counts = array_fill_keys(array_map(static fn (PublishState $state): string => $state->value, PublishState::cases()), 0);
        foreach ($this->states($type) as $state) {
            $counts[$state->value]++;
        }

        return $counts;
    }

    /**
     * Gives each snapshot published before 0.77 (no `_published_version`) the version it was
     * published from, as far as the content tells: the working version when the working copy
     * holds the published content (the full compare), else -1 (an older one), so states() no longer
     * compares that record in full. Snapshots that have the key are left alone, those whose working copy is
     * gone get -1; a second run marks nothing. Run by the `publishing.published_versions`
     * migrate step.
     *
     * @since 0.77
     *
     * @return int how many snapshots were marked
     */
    public function markPublishedVersions(string $type): int
    {
        $def = $this->definition($type);
        $shadow = ShadowTypes::published($def);
        if (!$this->dm->hasColumn($shadow, self::PUBLISHED_VERSION)) {
            return 0; // publishing.tables adds the column first
        }
        $legacy = [];
        foreach ($this->versions($shadow, self::PUBLISHED_VERSION, null) as $id => $version) {
            if ($version === null) {
                $legacy[] = (string) $id;
            }
        }

        $marked = 0;
        foreach (array_chunk($legacy, self::STATES_CHUNK) as $chunk) {
            $working = [];
            foreach ($this->among($this->dm->queryIn($def), $def, $chunk)->get()->items() as $record) {
                $working[(string) $record->getId()] = $record;
            }
            foreach ($this->among($this->dm->queryIn($shadow), $shadow, $chunk)->get()->items() as $snapshot) {
                $id = (string) $snapshot->getId();
                // An IN on a text column compares case- and accent-insensitively on MySQL: exact ids only.
                $read = $snapshot->get(self::PUBLISHED_VERSION);
                if (!in_array($id, $chunk, true) || ($read !== null && $read !== '')) {
                    continue;
                }
                // A snapshot without its working copy (an orphan) gets -1 too, so it is not read again.
                $version = isset($working[$id]) && self::compare($working[$id], $snapshot) === PublishState::Published
                    ? (int) ($working[$id]->get('_version') ?? 0)
                    : -1;
                // Only while it has none: a publish in between wrote its own.
                if ($this->dm->updateFieldsIfVersion($shadow, $id, [self::PUBLISHED_VERSION => $version], self::PUBLISHED_VERSION, null)) {
                    $marked++;
                }
            }
        }

        return $marked;
    }

    /**
     * One version column of a type's records by id, null where none is stored: an aggregate
     * grouped by the id and the column when the storage groups (only these two are read),
     * else the records.
     *
     * @param list<string>|null $ids
     * @return array<string, ?int>
     */
    private function versions(TypeDefinition $def, string $column, ?array $ids): array
    {
        $narrow = new TypeDefinition(
            key: $def->key,
            table: $def->table,
            storage: $def->storage,
            fields: [$column => new TypeFieldDefinition($column, 'string', exact: true)],
            idField: $def->idField,
        );
        $query = $this->among($this->dm->queryIn($narrow), $narrow, $ids);
        $version = static fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;

        $versions = [];
        if ($this->dm->driver($def->storage) instanceof AggregatingStorage) {
            foreach ($query->aggregate()->groupBy($def->idField, $column)->get() as $row) {
                $versions[(string) $row[$def->idField]] = $version($row[$column] ?? null);
            }

            return $versions;
        }
        foreach ($query->get()->items() as $record) {
            $versions[(string) $record->getId()] = $version($record->get($column));
        }

        return $versions;
    }

    /**
     * States by the full compare (state()) of these working copies (null: all), one snapshot query
     * per 500 ids.
     *
     * @param list<string>|null $ids
     * @return array<string, PublishState>
     */
    private function compared(TypeDefinition $def, ?array $ids): array
    {
        $shadow = ShadowTypes::published($def);
        $states = [];
        foreach ($ids === null ? [null] : array_chunk($ids, self::STATES_CHUNK) as $chunk) {
            $snapshots = [];
            foreach ($this->among($this->dm->queryIn($shadow), $shadow, $chunk)->get()->items() as $snapshot) {
                $snapshots[(string) $snapshot->getId()] = $snapshot;
            }
            foreach ($this->among($this->dm->queryIn($def), $def, $chunk)->get()->items() as $working) {
                $id = (string) $working->getId();
                // An IN on a text column compares case- and accent-insensitively on MySQL: exact ids only.
                if ($chunk !== null && !in_array($id, $chunk, true)) {
                    continue;
                }
                $states[$id] = self::compare($working, $snapshots[$id] ?? null);
            }
        }

        return $states;
    }

    /** @param list<string>|null $ids */
    private function among(QueryBuilder $query, TypeDefinition $def, ?array $ids): QueryBuilder
    {
        return $ids === null ? $query : $query->whereAny([['field' => $def->idField, 'operator' => 'IN', 'value' => $ids]]);
    }

    /**
     * Save the working copy without rules (half-finished work is fine) except what the storage
     * refuses (DataManager::validateStorable()): a taken unique value, an overlong bounded value. As version stored + 1. The version check is atomic with the write: of two saves holding the same
     * version, one gets VersionConflict, also when both passed the check at the same time.
     *
     * @param int|null $loadedVersion the _version the editor loaded; null skips the check
     *                                (a save conflicts then only if others save the record
     *                                during each of its 3 tries; inside a caller's transaction
     *                                the tries reread the same snapshot, so one lost race is
     *                                VersionConflict, or the driver's error on MariaDB/SQLite)
     * @throws VersionConflict
     * @throws ValidationException validation.unique on a taken unique field, validation.max.string
     *                             on an overlong bounded one (nothing saved)
     */
    public function saveDraft(DynamicRecord $working, ?int $loadedVersion): void
    {
        // A draft skips the rules, not what the storage refuses: a value another record has in a
        // unique field, a value longer than its bounded column.
        $this->dm->validateStorable($working);
        // The write itself carries the version it expects (DataManager::saveTypeIfVersion): a
        // save landing between this read and the write makes the write fail, never overwrite.
        // Without a loaded version, a save that lost such a race is retried on the newer version.
        for ($attempt = 1; ; $attempt++) {
            $stored = $this->dm->findIn($working->getType(), (string) $working->getId());
            $current = (int) ($stored?->get('_version') ?? 0);
            if ($loadedVersion !== null && $loadedVersion !== $current) {
                throw new VersionConflict($current);
            }
            if ($this->dm->saveTypeIfVersion($working, $current, $current + 1, validate: false)) {
                // A save without a content change (a schedule set or cancelled, the same text again)
                // keeps a published record Published in states(): its snapshot moves to the new version
                // (the fast path: no snapshot read when it stood for the version replaced). Otherwise,
                // a save whose content equals the snapshot's (an edit undone by hand, a snapshot edited
                // to the working content) marks the snapshot with the new version too.
                $same = $stored !== null && self::comparable($working) === self::comparable($stored);
                if (!$same || !$this->carryForward($working, $current)) {
                    $this->relinkSaved($working);
                }
                if (self::writesRun()) {
                    $this->index?->refresh($working);
                }

                return;
            }
            if ($loadedVersion !== null || $attempt >= self::SAVE_ATTEMPTS) {
                throw new VersionConflict($this->storedVersion($working));
            }
        }
    }

    /**
     * The snapshot published from version $from now stands for the working copy's new version (its
     * content is the same): a checked write of _published_version, so a publish in between keeps its
     * own. Never fails the save; a failure is logged.
     *
     * @return bool whether the snapshot was marked (false: none published from $from, the table not
     *              migrated, or the write failed)
     */
    private function carryForward(DynamicRecord $working, int $from): bool
    {
        $shadow = ShadowTypes::published($working->getType());
        try {
            return $this->dm->hasColumn($shadow, self::PUBLISHED_VERSION)
                && $this->dm->updateFieldsIfVersion($shadow, (string) $working->getId(), [self::PUBLISHED_VERSION => (int) $working->get('_version')], self::PUBLISHED_VERSION, $from);
        } catch (\Throwable $e) {
            $this->logger?->warning('Publishing: could not carry the published version of {type}/{id} forward: {message}', ['type' => $working->getType()->key, 'id' => (string) $working->getId(), 'message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
    }

    /**
     * After a draft save the fast path did not cover: one snapshot read; when the saved content
     * equals the snapshot's (the full compare), relink() marks the snapshot with the new version.
     */
    private function relinkSaved(DynamicRecord $working): void
    {
        $shadow = ShadowTypes::published($working->getType());
        try {
            if (!$this->dm->hasColumn($shadow, self::PUBLISHED_VERSION)) {
                return;
            }
            $snapshot = $this->dm->findIn($shadow, (string) $working->getId());
        } catch (\Throwable $e) {
            $this->logger?->warning('Publishing: could not read the snapshot of {type}/{id}: {message}', ['type' => $working->getType()->key, 'id' => (string) $working->getId(), 'message' => $e->getMessage(), 'exception' => $e]);

            return;
        }
        $this->relink($working, $snapshot);
    }

    private function storedVersion(DynamicRecord $working): int
    {
        return (int) ($this->dm->findIn($working->getType(), (string) $working->getId())?->get('_version') ?? 0);
    }

    /**
     * Validate fully, then save the working copy (schedule cleared) and copy
     * it to the snapshot.
     *
     * The working copy is written as version stored + 1 when its content differs from the
     * stored one (any field but the id and META: a site's approve that sets a status, an
     * import), so an editor still holding the old version conflicts instead of writing the old
     * content back. Unchanged content (Folio's form: the draft save, then publish; the
     * scheduler) keeps the version, and open editors stay valid. The record carries the
     * version written.
     *
     * With $loadedVersion (the _version the editor loaded), the working copy is written only if
     * it is still stored with that version, checked in the write as saveDraft() does; a stale
     * one throws VersionConflict (with the stored version) before any snapshot is written, and
     * the record is left as it was. On storage with transactions the working copy and the
     * snapshot are written in one transaction (a caller's open transaction is used as it is);
     * JSON writes the working copy first.
     *
     * Without it (null) the working copy is written unchecked, as the scheduler, the CLI and
     * importers (records built without _version) need.
     *
     * Publishing is the check of machine translations: LanguageChecks::clearMachineMarks() clears
     * the marks of the languages the record is visible in (_locales), in the working copy and the
     * snapshot, before the content comparison (so clearing them counts as a change and bumps the
     * version). The marks of languages it is not visible in stay: nobody looked at those.
     *
     * @throws ValidationException
     * @throws VersionConflict
     */
    public function publish(DynamicRecord $working, ?string $by, ?int $loadedVersion = null): DynamicRecord
    {
        $this->checks->validate($working);
        $this->requireCompleteLanguages($working);
        $before = $working->toArray();
        $this->languages?->clearMachineMarks($working);
        $cleared = $working->toArray();
        try {
            return $this->write($working, $by, $loadedVersion);
        } catch (\Throwable $e) {
            // The publish failed: the record keeps the marks it came with, as it keeps its schedule.
            foreach (array_keys($before + $cleared) as $key) {
                if (($before[$key] ?? null) === ($cleared[$key] ?? null) && array_key_exists($key, $before) === array_key_exists($key, $cleared)) {
                    continue;
                }
                array_key_exists($key, $before) ? $working->set($key, $before[$key]) : $working->remove($key);
            }
            throw $e;
        }
    }

    /**
     * publish() after the checks: the working copy (checked against the version expected: the loaded
     * one, else the one just read, tried again when another save got in between) and the snapshot,
     * in one transaction (DataManager::transaction()). On storage without transactions (JSON) a failed snapshot
     * write puts the stored schedule back, so a scheduled record is tried again on the next run.
     */
    private function write(DynamicRecord $working, ?string $by, ?int $loadedVersion): DynamicRecord
    {
        $def = $working->getType();
        $shadow = ShadowTypes::published($def);
        $id = (string) $working->getId();
        $before = $working->toArray();

        for ($attempt = 1; ; $attempt++) {
            // Read before any transaction: on MariaDB a transaction's later write to a row changed
            // since its first read fails (snapshot isolation); the checked write catches a save in between.
            $stored = $this->dm->findIn($def, $id);
            $changed = $stored === null || self::comparable($working) !== self::comparable($stored);
            $expected = $loadedVersion ?? (int) ($stored?->get('_version') ?? 0);
            $next = $changed ? $expected + 1 : $expected;

            $previous = null;
            $wrote = false;
            try {
                $snapshot = $this->dm->transaction([$def->storage, $shadow->storage], function () use ($working, $shadow, $id, $by, $expected, $next, &$previous, &$wrote): ?DynamicRecord {
                    // The schedule is cleared: '' is "no schedule", as a cancelled one (RecordWriter) is stored.
                    $working->set('_publish_at', '');
                    if (!$this->dm->saveTypeIfVersion($working, $expected, $next, validate: false)) {
                        return null;
                    }
                    $wrote = true;
                    $previous = $this->dm->findIn($shadow, $id);

                    return $this->writeSnapshot($working, $previous, $by);
                });
            } catch (\Throwable $e) {
                $this->putScheduleBack($working, $before, $wrote ? $next : null);
                throw $e;
            }
            if ($snapshot !== null) {
                break;
            }
            self::restoreKey($working, $before, '_publish_at');
            if ($loadedVersion !== null || $attempt >= self::SAVE_ATTEMPTS) {
                throw new VersionConflict($this->storedVersion($working));
            }
        }

        if (!self::writesRun()) {
            return $snapshot; // nothing was written (a preview, a view as a role): nothing follows it
        }
        $this->addresses?->changed(static fn (): ?DynamicRecord => $previous, $snapshot);
        // After the commit: the address book writes on its own (it never fails a publish).
        $this->addresses?->sync($def->key, $id, $previous);
        $this->index?->refresh($working);
        $this->activity->note($working, ActivityKind::Published, self::changedFields($def, $snapshot, $previous), $by);

        return $snapshot;
    }

    /**
     * After a failed publish: the record gets its schedule and version back; where the working copy
     * was written ($written: its version) and stays written (no transaction rolled it back), the
     * stored schedule is put back too, by a checked write.
     *
     * @param array<string, mixed> $before the record as publish() got it
     */
    private function putScheduleBack(DynamicRecord $working, array $before, ?int $written): void
    {
        self::restoreKey($working, $before, '_publish_at');
        self::restoreKey($working, $before, '_version');
        $schedule = $before['_publish_at'] ?? null;
        if ($written === null || !is_string($schedule) || $schedule === '') {
            return;
        }
        try {
            $this->dm->updateFieldsIfVersion($working->getType(), (string) $working->getId(), ['_publish_at' => $schedule], '_version', $written);
        } catch (\Throwable $e) {
            $this->logger?->warning('Publishing: could not put the schedule of {type}/{id} back: {message}', ['type' => $working->getType()->key, 'id' => (string) $working->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /** @param array<string, mixed> $before */
    private static function restoreKey(DynamicRecord $record, array $before, string $key): void
    {
        array_key_exists($key, $before) ? $record->set($key, $before[$key]) : $record->remove($key);
    }

    /**
     * With publishing.complete_languages, a record is not published while a language it is
     * visible in lacks texts the default language has (LanguageChecks::incomplete()).
     *
     * @throws ValidationException
     */
    private function requireCompleteLanguages(DynamicRecord $working): void
    {
        if (!$this->completeLanguages || $this->languages === null) {
            return;
        }

        $errors = $this->languages->incomplete($working);
        if ($errors !== []) {
            throw new ValidationException(new ValidationResult($errors));
        }
    }

    /** Copy a saved working copy to its snapshot and append a revision. */
    private function writeSnapshot(DynamicRecord $working, ?DynamicRecord $previous, ?string $by): DynamicRecord
    {
        $def = $working->getType();
        $shadow = ShadowTypes::published($def);

        $stamp = ($this->now)()->format(DATE_ATOM);
        $snapshot = DynamicRecord::fromArray($shadow, array_merge(
            array_diff_key($working->toArray(), array_flip(self::WORKING_ONLY)),
            ['_published_at' => $stamp, '_published_by' => $by],
        ));
        // The version it was published from (the one just written): states() compares it with the
        // working copy's. Left out on tables not migrated yet.
        if ($this->dm->hasColumn($shadow, self::PUBLISHED_VERSION)) {
            $snapshot->set(self::PUBLISHED_VERSION, (int) ($working->get('_version') ?? 0));
        }
        $this->dm->saveType($snapshot, false);
        $this->appendRevision($def, $snapshot, $previous, $stamp, $by);

        return $snapshot;
    }

    /**
     * Takes the record off the site. Its old addresses then redirect to $target, or answer 404
     * when there is none (RecordAddresses::removed()). $by (@since 0.77): who, for the activity
     * log, which hears of it when something was live.
     */
    public function unpublish(string $type, string $id, ?RemovalTarget $target = null, ?string $by = null): void
    {
        $run = self::writesRun();
        $live = $run && $this->activity->on() ? $this->snapshot($type, $id) : null;
        if ($run) {
            $this->addresses?->changed(fn (): ?DynamicRecord => $this->snapshot($type, $id), null);
        }
        $this->dm->deleteIn(ShadowTypes::published($this->definition($type)), $id);
        if ($run) {
            $this->addresses?->removed($type, $id, $target);
        }
        if ($live !== null) {
            $this->activity->note($this->dm->findIn($this->definition($type), $id) ?? $live, ActivityKind::Unpublished, [], $by);
        }
    }

    /**
     * Carry a working copy's list position into its snapshot (ordering is not content): only the
     * position is written, while the snapshot holds the published version read (a publish in between
     * copies the position itself).
     */
    public function syncOrder(DynamicRecord $working): void
    {
        $shadow = ShadowTypes::published($working->getType());
        $id = (string) $working->getId();
        $snapshot = $this->dm->findIn($shadow, $id);
        if ($snapshot === null) {
            return;
        }
        if (!$this->dm->hasColumn($shadow, self::PUBLISHED_VERSION)) {
            $snapshot->set('_order', $working->get('_order')); // tables not migrated yet
            $this->dm->saveType($snapshot, false);

            return;
        }
        $this->dm->updateFieldsIfVersion($shadow, $id, ['_order' => $working->get('_order')], self::PUBLISHED_VERSION, self::versionOf($snapshot->get(self::PUBLISHED_VERSION)));
    }

    /**
     * Write working-copy keys that are not content (the list position `_order`, the share token
     * `_share_token`) without a new version: only these keys, checked against the stored version
     * (tried again when a save got in between), so open editors stay valid and the state stays.
     *
     * @since 0.77
     *
     * @param array<string, mixed> $keys
     * @throws \InvalidArgumentException for any other key
     * @throws VersionConflict when the record was saved by others on every try
     */
    public function saveSystemKeys(string $type, string $id, array $keys): void
    {
        $unknown = array_diff(array_keys($keys), self::SYSTEM_KEYS);
        if ($keys === [] || $unknown !== []) {
            throw new \InvalidArgumentException('saveSystemKeys() writes ' . implode(', ', self::SYSTEM_KEYS) . ' only.');
        }
        $def = $this->definition($type);
        for ($attempt = 1; ; $attempt++) {
            $stored = $this->dm->findIn($def, $id) ?? throw new \InvalidArgumentException("No {$type} {$id} to write.");
            if ($this->dm->updateFieldsIfVersion($def, $id, $keys, '_version', self::versionOf($stored->get('_version')))) {
                return;
            }
            if ($attempt >= self::SAVE_ATTEMPTS) {
                throw new VersionConflict($this->storedVersion($stored));
            }
        }
    }

    /** A stored version: null for none (NULL, ''), else its number. */
    private static function versionOf(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** Remove a deleted record's snapshot and revisions. */
    public function forget(string $type, string $id): void
    {
        $def = $this->definition($type);
        if (self::writesRun()) {
            $this->addresses?->changed(fn (): ?DynamicRecord => $this->snapshot($type, $id), null);
        }
        $this->dm->deleteIn(ShadowTypes::published($def), $id);
        foreach ($this->revisionsOf($def, $id) as $revision) {
            $this->dm->deleteIn(ShadowTypes::revisions($def), $revision->id);
        }
    }

    /**
     * Reset the working copy to the published content; null when nothing is published.
     * With $loadedVersion, only if the working copy is still stored with it (else VersionConflict).
     * $publishAt as for restore(); callers discard through RecordWriter::discard(). $by
     * (@since 0.77): who, for the activity log (Discarded).
     *
     * @throws VersionConflict
     * @throws ValidationException when another record has taken a unique value since (nothing saved)
     */
    public function discard(string $type, string $id, ?int $loadedVersion = null, ?string $publishAt = null, ?string $by = null): ?DynamicRecord
    {
        $snapshot = $this->snapshot($type, $id);
        if ($snapshot === null) {
            return null;
        }
        $working = $this->replaceContent($type, $id, $snapshot->toArray(), $loadedVersion, $publishAt);
        $this->relink($working, $snapshot);
        if (self::writesRun()) {
            $this->activity->note($working, ActivityKind::Discarded, [], $by);
        }

        return $working;
    }

    /**
     * Revisions of a record, newest first.
     *
     * @return list<Revision>
     */
    public function revisions(string $type, string $id): array
    {
        return $this->revisionsOf($this->definition($type), $id);
    }

    /**
     * Copy a revision into the working copy; it must be published again to go live.
     * With $loadedVersion, only if the working copy is still stored with it (else VersionConflict).
     * $publishAt is the schedule the restored working copy gets: null keeps the stored one, ''
     * cancels it. Callers restore through RecordWriter::restore(), which applies the schedule rule
     * (who may not publish cancels a pending schedule); calling this directly skips it. $by
     * (@since 0.77): who, for the activity log.
     *
     * @throws VersionConflict
     * @throws ValidationException when the storage refuses the revision's content (validateStorable(); nothing saved)
     */
    public function restore(string $type, string $id, string $revisionId, ?int $loadedVersion = null, ?string $publishAt = null, ?string $by = null): ?DynamicRecord
    {
        foreach ($this->revisions($type, $id) as $revision) {
            if ($revision->id === $revisionId) {
                $restored = $this->replaceContent($type, $id, $revision->data, $loadedVersion, $publishAt);
                $this->relink($restored, $this->snapshot($type, $id));
                if (self::writesRun()) {
                    $this->activity->note($restored, ActivityKind::Restored, [], $by);
                }

                return $restored;
            }
        }

        return null;
    }

    /**
     * After a discard, a restore or a draft save (a new working version): when the working copy now holds the
     * published content (the full compare), its snapshot is marked as published from that version,
     * so states() reads Published as state() does. A checked write of that key only (a publish in
     * between keeps its own), and never to an older version than the snapshot holds (a later save
     * marked it first); a failure is logged and never fails the write.
     */
    private function relink(DynamicRecord $working, ?DynamicRecord $snapshot): void
    {
        $version = (int) ($working->get('_version') ?? 0);
        $read = self::versionOf($snapshot?->get(self::PUBLISHED_VERSION));
        // Never back: a snapshot already marked with a newer version (a later save got there first) keeps it.
        if ($snapshot === null || $read === $version || ($read !== null && $read > $version) || self::compare($working, $snapshot) !== PublishState::Published) {
            return;
        }
        try {
            if ($this->dm->hasColumn($snapshot->getType(), self::PUBLISHED_VERSION)) {
                $this->dm->updateFieldsIfVersion($snapshot->getType(), (string) $snapshot->getId(), [self::PUBLISHED_VERSION => $version], self::PUBLISHED_VERSION, $read);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Publishing: could not mark the published version of {type}/{id}: {message}', ['type' => $working->getType()->key, 'id' => (string) $working->getId(), 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /** @return list<Revision> */
    private function revisionsOf(TypeDefinition $def, string $id): array
    {
        $revisions = [];
        foreach ($this->dm->queryIn(ShadowTypes::revisions($def))->where('record_id', $id)->get()->items() as $row) {
            // record_id is a value column, which MySQL compares case- and accent-insensitively: keep exact matches only.
            if ((string) $row->get('record_id') !== $id) {
                continue;
            }
            $data = json_decode((string) $row->get('data'), true);
            $changed = json_decode((string) $row->get('changed'), true);
            $revisions[] = new Revision(
                (string) $row->getId(),
                (int) $row->get('seq'),
                is_array($data) ? $data : [],
                is_array($changed) ? array_values(array_filter($changed, 'is_string')) : [],
                (string) $row->get('created_at'),
                is_string($row->get('created_by')) ? $row->get('created_by') : null,
            );
        }
        usort($revisions, static fn (Revision $a, Revision $b) => $b->seq <=> $a->seq);

        return $revisions;
    }

    private function appendRevision(TypeDefinition $def, DynamicRecord $snapshot, ?DynamicRecord $previous, string $stamp, ?string $by): void
    {
        $id = (string) $snapshot->getId();
        $existing = $this->revisionsOf($def, $id);
        $revisionsDef = ShadowTypes::revisions($def);

        $this->dm->saveType(DynamicRecord::fromArray($revisionsDef, [
            'uuid' => bin2hex(random_bytes(8)),
            'record_id' => $id,
            'seq' => ($existing[0]->seq ?? 0) + 1,
            'data' => json_encode($snapshot->toArray()),
            'changed' => json_encode(self::changedFields($def, $snapshot, $previous)),
            'created_at' => $stamp,
            'created_by' => $by,
        ]), false);

        if ($this->keepRevisions === null || $this->keepRevisions === 0) {
            return;
        }
        foreach (array_slice($existing, $this->keepRevisions - 1) as $old) {
            $this->dm->deleteIn($revisionsDef, $old->id);
        }
    }

    /**
     * Top-level fields that differ from the previous snapshot; for a first
     * publish, the fields that have a value.
     *
     * @return list<string>
     */
    private static function changedFields(TypeDefinition $def, DynamicRecord $snapshot, ?DynamicRecord $previous): array
    {
        $changed = [];
        $nowAll = $snapshot->toArray();
        $beforeAll = $previous?->toArray() ?? [];
        foreach (array_keys($def->fields) as $name) {
            if ($name === $def->idField) {
                continue;
            }
            $now = $nowAll[$name] ?? null;
            $before = $beforeAll[$name] ?? null;
            if (self::isEmpty($now) && self::isEmpty($before)) {
                continue;
            }
            if ($previous === null || json_encode(self::sortKeys($now)) !== json_encode(self::sortKeys($before))) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    /**
     * Overwrite the working copy's content, keeping its system keys (the schedule too, unless
     * $publishAt is given) and bumping its version (so open editors see a conflict).
     *
     * @param array<string, mixed> $content
     * @throws VersionConflict when $loadedVersion is stale, or (without one) a save landed since the read
     * @throws ValidationException when the storage refuses the content
     */
    private function replaceContent(string $type, string $id, array $content, ?int $loadedVersion = null, ?string $publishAt = null): DynamicRecord
    {
        $def = $this->definition($type);
        $working = $this->dm->findIn($def, $id);

        $data = array_diff_key($content, array_flip(self::META));
        foreach (['_share_token', '_order', '_publish_at'] as $key) {
            $value = $working?->get($key);
            if ($value !== null) {
                $data[$key] = $value;
            }
        }
        if ($publishAt !== null) {
            $data['_publish_at'] = $publishAt;
        }
        // A snapshot from before a field, a translation key (LanguageChecks::stateKeys()) or an app's
        // column (RecordColumns, but the system keys) existed has no such key; SQL leaves unnamed columns
        // as they are, which would keep the working row's current ones.
        // Other columns (a site's own) are not content and stay as they are.
        $columns = array_diff($this->columns?->columns($def) ?? [], self::META);
        foreach ([...array_keys($def->fields), ...($this->languages?->stateKeys() ?? []), ...$columns] as $key) {
            if ($key !== $def->idField && !array_key_exists($key, $data)) {
                $data[$key] = null;
            }
        }
        $data[$def->idField] = $id;
        $data['_version'] = (int) ($working?->get('_version') ?? 0) + 1;

        $record = DynamicRecord::fromArray($def, $data);
        // A revision may hold a unique value another record has taken since, or one longer than its column.
        $this->dm->validateStorable($record);
        // Always a new version (a plain save keeps it when the content is the same): checked against
        // the loaded one, else the one just read.
        if (!$this->dm->saveTypeIfVersion($record, $loadedVersion ?? (int) ($working?->get('_version') ?? 0), validate: false)) {
            throw new VersionConflict($this->storedVersion($record));
        }
        if (self::writesRun()) {
            $this->index?->refresh($record);
        }

        return $record;
    }

    /**
     * Whether writes run for real in the current render (Allow, or no render at all). Under Skip
     * (a preview, a view as a role, the audit) DataManager skips and reports the data writes, and
     * nothing that follows them runs: the index, the addresses and the page cache, the activity
     * log. Under Refuse the data write has thrown already.
     */
    private static function writesRun(): bool
    {
        return Writes::policy() === WritePolicy::Allow;
    }

    private function definition(string $type): TypeDefinition
    {
        $registry = $this->dm->getTypeRegistry() ?? throw new \LogicException('Publishing needs a TypeRegistry.');

        return $registry->get($type);
    }

    private static function compare(DynamicRecord $working, ?DynamicRecord $snapshot): PublishState
    {
        if ($snapshot === null) {
            return PublishState::Draft;
        }

        // As stored on both sides (comparable()), as changes() compares: the two never disagree.
        return self::comparable($working) === self::comparable($snapshot) ? PublishState::Published : PublishState::Changed;
    }

    /**
     * The record's content (everything but the id and META, keys sorted) as stored, so a record built in PHP and the same record read back from SQL
     * compare equal: JSON text decoded, numbers and booleans as the text SQL returns.
     */
    private static function comparable(DynamicRecord $record): string
    {
        return (string) json_encode(self::sortKeys(self::normalized($record)));
    }

    /**
     * The record's content as stored (see comparable()): everything but the id and META, empty
     * values left out.
     *
     * @return array<string, mixed>
     */
    private static function normalized(DynamicRecord $record): array
    {
        $fields = $record->getType()->fields;
        $data = array_diff_key($record->toArray(), array_flip([...self::META, $record->getType()->idField]));
        foreach ($data as $name => $value) {
            // A boolean comes back as '1', '0' or '' (SQLite stores false as ''): compare its truth.
            $data[$name] = ($fields[$name]->type ?? null) === 'boolean'
                ? (in_array($value, [true, 1, '1'], true) ? '1' : '')
                : self::asStored($value);
        }
        return array_filter($data, static fn (mixed $value): bool => !self::isEmpty($value));
    }

    private static function asStored(mixed $value): mixed
    {
        if (is_string($value) && ($value[0] ?? '') !== '' && str_contains('[{', $value[0])) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        return match (true) {
            is_array($value) => array_map(self::asStored(...), $value),
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $value,
        };
    }

    /** null, '', an empty list or JSON '[]', or a locale map/list of only such values. */
    private static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === '[]') {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!self::isEmpty($item)) {
                return false;
            }
        }

        return true;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
