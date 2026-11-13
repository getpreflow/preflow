<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Data\DataManager;
use Preflow\Data\DeletePlan;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\PublishedCopies;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Publishing\WriteKind;

/**
 * Carries a member's own deletion (MemberEvents::DELETED, the applied plan) into Folio's
 * publishing types, as a staff delete would:
 *
 * - each deleted record of a publishing type is forgotten (snapshot and revisions: unpublished);
 * - no published snapshot keeps pointing at a deleted record: the deleted ids are taken out of
 *   every snapshot whose relation field holds them (also snapshot-only pointers; the snapshot is
 *   edited in place, without a revision; older revisions keep their history);
 * - then a detached working copy of a publishing type is saved again through the RecordWriter, as
 *   a staff delete saves it: the type's save hooks run and it gets a new draft version (an open
 *   editor sees the change instead of overwriting it). Its content is detached already, so a save
 *   that fails (a hook refusing it) is logged and the others go on; the snapshots are scrubbed
 *   before, whatever these saves do.
 *
 * Best effort: it runs after the deletion, which stands. A failure here is logged by MemberEvents
 * (as every DELETED listener's is) and leaves the snapshots as they were from that point on.
 */
final class PublishedMemberDeletion
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly Publisher $publisher,
        private readonly PublishedCopies $copies,
        private readonly RecordWriter $writer,
        /** @var \Closure(string): void|null where a failed version bump is logged; null: error_log() */
        private readonly ?\Closure $log = null,
    ) {}

    /** @param array<string, mixed> $payload the DELETED payload */
    public function __invoke(array $payload): void
    {
        $plan = $payload['plan'] ?? null;
        if (!$plan instanceof DeletePlan) {
            return;
        }

        foreach ($plan->deletes() as $type => $ids) {
            if ($this->publisher->enabled($type)) {
                foreach ($ids as $id) {
                    $this->publisher->forget($type, $id);
                }
            }
        }

        $gone = $plan->deletes();
        foreach ($gone as $target => $ids) {
            foreach ($this->dm->relationsTo($target) as $relation) {
                $def = $this->dm->getTypeRegistry()?->get($relation->type);
                if ($def === null) {
                    continue;
                }
                foreach ($this->copies->of($def) as $shadow) {
                    $this->clear($shadow, $relation->field, $ids, $gone[$relation->type] ?? []);
                }
            }
        }

        $bumped = [];
        foreach ($plan->detaches() as $detach) {
            $key = $detach['type'] . "\0" . $detach['id'];
            if (isset($bumped[$key]) || !$this->publisher->enabled($detach['type'])) {
                continue;
            }
            $bumped[$key] = true;
            $working = $this->dm->findType($detach['type'], $detach['id']);
            if ($working === null) {
                continue;
            }
            try {
                $this->writer->save($working, new SaveOptions(kind: WriteKind::Detach)); // already detached; this moves its version on
            } catch (\Throwable $e) {
                $message = "Member deletion: the detached {$detach['type']} \"{$detach['id']}\" was not saved again: " . $e->getMessage();
                $this->log !== null ? ($this->log)($message) : error_log($message);
            }
        }
    }

    /**
     * Takes $ids out of $field in every snapshot of $shadow that holds one; snapshots of records
     * deleted themselves ($deleted, already forgotten) are left alone.
     *
     * @param list<string> $ids
     * @param list<string> $deleted
     */
    private function clear(\Preflow\Data\TypeDefinition $shadow, string $field, array $ids, array $deleted): void
    {
        foreach (array_chunk($ids, 400) as $chunk) {
            $rows = $this->dm->queryIn($shadow)
                ->whereAny([['field' => $field, 'operator' => 'HAS', 'value' => $chunk]])
                ->get()
                ->items();
            foreach ($rows as $snapshot) {
                /** @var DynamicRecord $snapshot */
                if (in_array((string) $snapshot->getId(), $deleted, true)) {
                    continue;
                }
                $value = $snapshot->get($field);
                $next = DeletePlan::valueWithout($value, $chunk);
                if ($next !== $value) {
                    $snapshot->set($field, $next);
                    $this->dm->saveType($snapshot, false);
                }
            }
        }
    }
}
