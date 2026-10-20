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
        $snapshot = $this->dm->findIn(ShadowTypes::published($working->getType()), $id);

        return self::compare($working, $snapshot);
    }

    /**
     * States of every working copy of a type, with one snapshot query.
     *
     * @return array<string, PublishState> keyed by id
     */
    public function states(string $type): array
    {
        $def = $this->definition($type);
        $snapshots = [];
        foreach ($this->dm->queryIn(ShadowTypes::published($def))->get()->items() as $snapshot) {
            $snapshots[(string) $snapshot->getId()] = $snapshot;
        }

        $states = [];
        foreach ($this->dm->queryIn($def)->get()->items() as $working) {
            $id = (string) $working->getId();
            $states[$id] = self::compare($working, $snapshots[$id] ?? null);
        }

        return $states;
    }

    /**
     * Save the working copy without rules (half-finished work is fine).
     *
     * @param int|null $loadedVersion the _version the editor loaded; null skips the check
     * @throws VersionConflict
     */
    public function saveDraft(DynamicRecord $working, ?int $loadedVersion): void
    {
        $stored = $this->dm->findIn($working->getType(), (string) $working->getId());
        $current = (int) ($stored?->get('_version') ?? 0);
        if ($loadedVersion !== null && $loadedVersion !== $current) {
            throw new VersionConflict($current);
        }

        $working->set('_version', $current + 1);
        $this->dm->saveType($working, false);
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

        $working->set('_publish_at', null);
        $this->dm->saveType($working, false);

        $snapshot = DynamicRecord::fromArray($shadow, array_merge(
            array_diff_key($working->toArray(), array_flip(self::WORKING_ONLY)),
            ['_published_at' => ($this->now)()->format(DATE_ATOM), '_published_by' => $by],
        ));
        $this->dm->saveType($snapshot, false);

        return $snapshot;
    }

    public function unpublish(string $type, string $id): void
    {
        $this->dm->deleteIn(ShadowTypes::published($this->definition($type)), $id);
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

        return (string) json_encode(self::sortKeys($data));
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
