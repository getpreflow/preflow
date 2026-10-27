<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Validation\ValidationException;

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

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly RecordValidator $validator,
        private readonly int $keepRevisions = 20,
        ?\Closure $now = null,
    ) {
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
     * the ones with these ids (ids that do not exist are left out).
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
        $only = $ids === null ? null : array_values(array_map('strval', $ids));
        $snapshots = [];
        foreach ($this->among($this->dm->queryIn($shadow), $shadow, $only)->get()->items() as $snapshot) {
            $snapshots[(string) $snapshot->getId()] = $snapshot;
        }

        $states = [];
        foreach ($this->among($this->dm->queryIn($def), $def, $only)->get()->items() as $working) {
            $id = (string) $working->getId();
            // An IN on a text column compares case- and accent-insensitively on MySQL: exact ids only.
            if ($only !== null && !in_array($id, $only, true)) {
                continue;
            }
            $states[$id] = self::compare($working, $snapshots[$id] ?? null);
        }

        return $states;
    }

    /** @param list<string>|null $ids */
    private function among(QueryBuilder $query, TypeDefinition $def, ?array $ids): QueryBuilder
    {
        return $ids === null ? $query : $query->whereAny([['field' => $def->idField, 'operator' => 'IN', 'value' => $ids]]);
    }

    /**
     * Save the working copy without rules (half-finished work is fine), as version
     * stored + 1. The version check is atomic with the write: of two saves holding the same
     * version, one gets VersionConflict, also when both passed the check at the same time.
     *
     * @param int|null $loadedVersion the _version the editor loaded; null skips the check
     *                                (a save never conflicts then, unless the record stays busy)
     * @throws VersionConflict
     */
    public function saveDraft(DynamicRecord $working, ?int $loadedVersion): void
    {
        // The write itself carries the version it expects (DataManager::saveTypeIfVersion): a
        // save landing between this read and the write makes the write fail, never overwrite.
        // Without a loaded version, a save that lost such a race is retried on the newer version.
        for ($attempt = 1; ; $attempt++) {
            $current = $this->storedVersion($working);
            if ($loadedVersion !== null && $loadedVersion !== $current) {
                throw new VersionConflict($current);
            }
            if ($this->dm->saveTypeIfVersion($working, $current, $current + 1, validate: false)) {
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
     * @throws ValidationException
     */
    public function publish(DynamicRecord $working, ?string $by): DynamicRecord
    {
        $this->validator->validate($working);

        $def = $working->getType();
        $shadow = ShadowTypes::published($def);

        $previous = $this->dm->findIn($shadow, (string) $working->getId());

        // '' rather than null: SQL drivers skip null values, so null would never clear it.
        $working->set('_publish_at', '');
        $this->dm->saveType($working, false);

        $stamp = ($this->now)()->format(DATE_ATOM);
        $snapshot = DynamicRecord::fromArray($shadow, array_merge(
            array_diff_key($working->toArray(), array_flip(self::WORKING_ONLY)),
            ['_published_at' => $stamp, '_published_by' => $by],
        ));
        $this->dm->saveType($snapshot, false);
        $this->appendRevision($def, $snapshot, $previous, $stamp, $by);

        return $snapshot;
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

    /** Reset the working copy to the published content; null when nothing is published. */
    public function discard(string $type, string $id): ?DynamicRecord
    {
        $snapshot = $this->snapshot($type, $id);
        if ($snapshot === null) {
            return null;
        }

        return $this->replaceContent($type, $id, $snapshot->toArray());
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

    /** Copy a revision into the working copy; it must be published again to go live. */
    public function restore(string $type, string $id, string $revisionId): ?DynamicRecord
    {
        foreach ($this->revisions($type, $id) as $revision) {
            if ($revision->id === $revisionId) {
                return $this->replaceContent($type, $id, $revision->data);
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

        foreach (array_slice($existing, max(0, $this->keepRevisions - 1)) as $old) {
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
     */
    private function replaceContent(string $type, string $id, array $content): DynamicRecord
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
        $data[$def->idField] = $id;
        $data['_version'] = (int) ($working?->get('_version') ?? 0) + 1;

        $record = DynamicRecord::fromArray($def, $data);
        $this->dm->saveType($record, false);

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
