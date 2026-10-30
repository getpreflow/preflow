<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\ReviewIndexUpdater;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * Working copy (what the admin edits, the type's own table) and published
 * snapshot (what the public site reads, {table}__published). States are
 * derived by comparing content; system keys in META never count as content.
 */
final class Publisher
{
    public const array META = ['_version', '_publish_at', '_share_token', '_published_at', '_published_by', '_order'];

    /** Working-copy-only keys that are not copied into a snapshot. */
    private const array WORKING_ONLY = ['_version', '_publish_at', '_share_token'];

    /** How often saveDraft() without a loaded version tries before giving up on a busy record. */
    private const int SAVE_ATTEMPTS = 3;

    /** How many ids states() reads per query. */
    private const int STATES_CHUNK = 500;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly RecordValidator $validator,
        private readonly ?int $keepRevisions = 20,
        ?\Closure $now = null,
        private readonly ?TranslationStatus $translations = null,
        private readonly bool $completeLanguages = false,
        private readonly ?ReviewIndexUpdater $review = null,
    ) {
        if ($keepRevisions !== null && $keepRevisions < 0) {
            throw new \InvalidArgumentException('publishing.revisions must be 0 (keep all) or more.');
        }
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function enabled(string $type): bool
    {
        return $this->catalog->get($type)?->publishing ?? false;
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
     * States of a type's working copies, with one snapshot query: every working copy, or
     * the ones with these ids (ids that do not exist are left out; one query per 500 ids).
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

        $states = [];
        foreach ($chunks as $chunk) {
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
            $current = $this->storedVersion($working);
            if ($loadedVersion !== null && $loadedVersion !== $current) {
                throw new VersionConflict($current);
            }
            if ($this->dm->saveTypeIfVersion($working, $current, $current + 1, validate: false)) {
                $this->review?->refresh($working);

                return;
            }
            if ($loadedVersion !== null || $attempt >= self::SAVE_ATTEMPTS) {
                throw new VersionConflict($this->storedVersion($working));
            }
        }
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
     * the record is left as it was. On SQL storage the working copy and the snapshot are written
     * in one transaction (a caller's open transaction is used as it is); JSON writes the working
     * copy first.
     *
     * Without it (null) the working copy is written unchecked, as the scheduler, the CLI and
     * importers (records built without _version) need.
     *
     * Publishing is the check of machine translations: the machine marks of the languages the
     * record is visible in (_locales) are cleared, in the working copy and the snapshot, before
     * the content comparison (so clearing them counts as a change and bumps the version). The
     * marks of languages it is not visible in stay: nobody looked at those.
     *
     * @throws ValidationException
     * @throws VersionConflict
     */
    public function publish(DynamicRecord $working, ?string $by, ?int $loadedVersion = null): DynamicRecord
    {
        $this->validator->validate($working);
        $this->requireCompleteLanguages($working);
        $marksBefore = $working->toArray()[TranslationStatus::MACHINE] ?? null;
        $this->clearVisibleMarks($working);
        try {
            return $this->write($working, $by, $loadedVersion);
        } catch (\Throwable $e) {
            // The publish failed: the record keeps the marks it came with, as it keeps its schedule.
            $marksBefore !== null ? $working->set(TranslationStatus::MACHINE, $marksBefore) : $working->remove(TranslationStatus::MACHINE);
            throw $e;
        }
    }

    /** publish() after the checks: the working copy and the snapshot. */
    private function write(DynamicRecord $working, ?string $by, ?int $loadedVersion): DynamicRecord
    {
        $def = $working->getType();
        $shadow = ShadowTypes::published($def);

        // Read before any transaction: on MariaDB a transaction's later write to a row changed
        // since its first read fails (snapshot isolation); the claim catches a save in between.
        $stored = $this->dm->findIn($def, (string) $working->getId());
        $changed = $stored === null || self::comparable($working) !== self::comparable($stored);

        if ($loadedVersion === null) {
            $previous = $this->dm->findIn($shadow, (string) $working->getId());
            $storedVersion = $stored?->get('_version');
            if ($changed) {
                $working->set('_version', (int) ($storedVersion ?? 0) + 1);
            } elseif ($storedVersion !== null) {
                $working->set('_version', (int) $storedVersion);
            }

            // '' rather than null: SQL drivers skip null values, so null would never clear it.
            $working->set('_publish_at', '');
            $this->dm->saveType($working, false);
            $snapshot = $this->writeSnapshot($working, $previous, $by);
            $this->review?->refresh($working);

            return $snapshot;
        }

        $next = $changed ? $loadedVersion + 1 : $loadedVersion;

        $snapshot = $this->transaction([$def, $shadow], function () use ($working, $shadow, $by, $loadedVersion, $next): DynamicRecord {
            $schedule = $working->toArray();
            $working->set('_publish_at', '');
            if (!$this->dm->saveTypeIfVersion($working, $loadedVersion, $next, validate: false)) {
                array_key_exists('_publish_at', $schedule) ? $working->set('_publish_at', $schedule['_publish_at']) : $working->remove('_publish_at');
                throw new VersionConflict($this->storedVersion($working));
            }

            return $this->writeSnapshot($working, $this->dm->findIn($shadow, (string) $working->getId()), $by);
        });
        $this->review?->refresh($working);

        return $snapshot;
    }

    /** Clears the machine marks of the languages $working is visible in, on $working itself. */
    private function clearVisibleMarks(DynamicRecord $working): void
    {
        if ($this->translations === null) {
            return;
        }
        $cleared = $this->translations->clearLocales($working, $working->availableLocales())->toArray();
        if (array_key_exists(TranslationStatus::MACHINE, $cleared)) {
            $working->set(TranslationStatus::MACHINE, $cleared[TranslationStatus::MACHINE]);
        }
    }

    /**
     * With folio.publishing.complete_languages, a record is not published while a language it
     * is visible in lacks texts the default language has. Outdated translations never block.
     *
     * @throws ValidationException
     */
    private function requireCompleteLanguages(DynamicRecord $working): void
    {
        if (!$this->completeLanguages || $this->translations === null) {
            return;
        }

        $def = $working->getType();
        $errors = [];
        foreach ($working->availableLocales() as $locale) {
            if ($locale === $def->locales->default) {
                continue;
            }
            $missing = $this->translations->missing($def, $working, $locale);
            if ($missing === []) {
                continue;
            }
            $errors['_translations.' . $locale] = [FolioText::get('folio.publish.incomplete', [
                'language' => self::languageName($locale),
                'texts' => self::labelList($missing),
            ])];
        }

        if ($errors !== []) {
            throw new ValidationException(new ValidationResult($errors));
        }
    }

    private static function languageName(string $locale): string
    {
        $name = FolioText::get('folio.languages.' . $locale);

        return $name !== 'folio.languages.' . $locale ? $name : strtoupper($locale);
    }

    /** @param list<\Preflow\Folio\Content\TranslatableText> $texts the first five labels, then "(+N)" */
    private static function labelList(array $texts): string
    {
        $labels = array_map(static fn ($t): string => $t->label, $texts);

        return implode(', ', array_slice($labels, 0, 5)) . (count($labels) > 5 ? ' … (+' . (count($labels) - 5) . ')' : '');
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
        $this->dm->saveType($snapshot, false);
        $this->appendRevision($def, $snapshot, $previous, $stamp, $by);

        return $snapshot;
    }

    /**
     * Run $writes in one transaction per SQL connection the types are stored on (one already
     * open is left to its owner); rolled back on any error. JSON storage has none.
     *
     * @template T
     * @param list<TypeDefinition> $types
     * @param \Closure(): T $writes
     * @return T
     */
    private function transaction(array $types, \Closure $writes): mixed
    {
        /** @var array<int, \PDO> $began */
        $began = [];
        try {
            foreach ($types as $type) {
                $driver = $this->dm->driver($type->storage);
                if (!$driver instanceof PdoDriver) {
                    continue;
                }
                $pdo = $driver->pdo();
                if (!isset($began[spl_object_id($pdo)]) && !$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $began[spl_object_id($pdo)] = $pdo;
                }
            }
            $result = $writes();
            foreach ($began as $pdo) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            foreach ($began as $pdo) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            throw $e;
        }
    }

    public function unpublish(string $type, string $id): void
    {
        $this->dm->deleteIn(ShadowTypes::published($this->definition($type)), $id);
    }

    /** Carry a working copy's list position into its snapshot (ordering is not content). */
    public function syncOrder(DynamicRecord $working): void
    {
        $shadow = ShadowTypes::published($working->getType());
        $snapshot = $this->dm->findIn($shadow, (string) $working->getId());
        if ($snapshot !== null) {
            $snapshot->set('_order', $working->get('_order'));
            $this->dm->saveType($snapshot, false);
        }
    }

    /** Remove a deleted record's snapshot and revisions. */
    public function forget(string $type, string $id): void
    {
        $def = $this->definition($type);
        $this->dm->deleteIn(ShadowTypes::published($def), $id);
        foreach ($this->revisionsOf($def, $id) as $revision) {
            $this->dm->deleteIn(ShadowTypes::revisions($def), $revision->id);
        }
    }

    /**
     * Reset the working copy to the published content; null when nothing is published.
     * With $loadedVersion, only if the working copy is still stored with it (else VersionConflict).
     *
     * @throws VersionConflict
     * @throws ValidationException when another record has taken a unique value since (nothing saved)
     */
    public function discard(string $type, string $id, ?int $loadedVersion = null): ?DynamicRecord
    {
        $snapshot = $this->snapshot($type, $id);
        if ($snapshot === null) {
            return null;
        }

        return $this->replaceContent($type, $id, $snapshot->toArray(), $loadedVersion);
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
     *
     * @throws VersionConflict
     * @throws ValidationException when the storage refuses the revision's content (validateStorable(); nothing saved)
     */
    public function restore(string $type, string $id, string $revisionId, ?int $loadedVersion = null): ?DynamicRecord
    {
        foreach ($this->revisions($type, $id) as $revision) {
            if ($revision->id === $revisionId) {
                return $this->replaceContent($type, $id, $revision->data, $loadedVersion);
            }
        }

        return null;
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
        foreach (array_keys($def->fields) as $name) {
            if ($name === $def->idField) {
                continue;
            }
            $now = $snapshot->toArray()[$name] ?? null;
            $before = $previous?->toArray()[$name] ?? null;
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
     * Overwrite the working copy's content, keeping its system keys and
     * bumping its version (so open editors see a conflict).
     *
     * @param array<string, mixed> $content
     * @throws VersionConflict when $loadedVersion is given and stale
     * @throws ValidationException when the storage refuses the content
     */
    private function replaceContent(string $type, string $id, array $content, ?int $loadedVersion = null): DynamicRecord
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
        // A snapshot from before fingerprints, sources or machine marks existed has no such key; SQL leaves
        // unnamed columns as they are, which would keep the working row's current ones.
        foreach ([TranslationStatus::KEY, TranslationStatus::SOURCES, TranslationStatus::MACHINE] as $key) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = null;
            }
        }
        $data[$def->idField] = $id;
        $data['_version'] = (int) ($working?->get('_version') ?? 0) + 1;

        $record = DynamicRecord::fromArray($def, $data);
        // A revision may hold a unique value another record has taken since, or one longer than its column.
        $this->dm->validateStorable($record);
        if ($loadedVersion === null) {
            $this->dm->saveType($record, false);
        } elseif (!$this->dm->saveTypeIfVersion($record, $loadedVersion, validate: false)) {
            throw new VersionConflict($this->storedVersion($record));
        }
        $this->review?->refresh($record);

        return $record;
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

        return self::content($working) === self::content($snapshot) ? PublishState::Published : PublishState::Changed;
    }

    /** Canonical content of a record: everything but the id and META, keys sorted. */
    private static function content(DynamicRecord $record): string
    {
        $data = array_diff_key($record->toArray(), array_flip([...self::META, $record->getType()->idField]));
        // A field never saved and a field saved empty (as a form does) are the same content.
        $data = array_filter($data, static fn (mixed $value): bool => !self::isEmpty($value));

        return (string) json_encode(self::sortKeys($data));
    }

    /**
     * content() as stored, so a record built in PHP and the same record read back from SQL
     * compare equal: JSON text decoded, numbers and booleans as the text SQL returns.
     */
    private static function comparable(DynamicRecord $record): string
    {
        $fields = $record->getType()->fields;
        $data = array_diff_key($record->toArray(), array_flip([...self::META, $record->getType()->idField]));
        foreach ($data as $name => $value) {
            // A boolean comes back as '1', '0' or '' (SQLite stores false as ''): compare its truth.
            $data[$name] = ($fields[$name]->type ?? null) === 'boolean'
                ? (in_array($value, [true, 1, '1'], true) ? '1' : '')
                : self::asStored($value);
        }
        $data = array_filter($data, static fn (mixed $value): bool => !self::isEmpty($value));

        return (string) json_encode(self::sortKeys($data));
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
