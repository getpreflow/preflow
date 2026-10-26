<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Data\DataManager;
use Preflow\Data\DeletePlan;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\PublishedCopies;
use Preflow\Folio\Publishing\Publisher;

/**
 * Carries a member's own deletion (MemberEvents::DELETED, the applied plan) into Folio's
 * publishing types, as a staff delete would:
 *
 * - each deleted record of a publishing type is forgotten (snapshot and revisions: unpublished);
 * - a detached working copy of a publishing type gets a new draft version (an open editor sees
 *   the change instead of overwriting it);
 * - no published snapshot keeps pointing at a deleted record: the deleted ids are taken out of
 *   every snapshot whose relation field holds them (also snapshot-only pointers; the snapshot is
 *   edited in place, without a revision; older revisions keep their history).
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

        $bumped = [];
        foreach ($plan->detaches() as $detach) {
            $key = $detach['type'] . "\0" . $detach['id'];
            if (isset($bumped[$key]) || !$this->publisher->enabled($detach['type'])) {
                continue;
            }
            $bumped[$key] = true;
            $working = $this->dm->findType($detach['type'], $detach['id']);
            if ($working !== null) {
                $this->publisher->saveDraft($working, null); // already detached; this moves its version on
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
