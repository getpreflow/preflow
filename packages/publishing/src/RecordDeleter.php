<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\DeletedBy;
use Preflow\Data\DeletePlan;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Validation\ValidationException;

/**
 * The one delete path for records: through the data package's delete plan, executed record by
 * record, so a cascaded record goes exactly like a single delete and a detach is a save of the
 * pointing record. It returns data only; an admin words the plan and a refusal for editors
 * (Folio: DeleteSummary).
 *
 * delete() computes the plan itself, right before executing it: a plan shown in an earlier
 * request is never executed.
 *
 * Order: plan → DeleteRefused if restricted → SaveHooks::before() on every detached record (the
 * ids already removed) and SaveHooks::beforeDelete() on every record to delete, all before any
 * write (DeleteRefused, or the hook's own exception, cancels everything) → the writes (detaches
 * via Publisher::saveDraft(), or as RecordWriter stores a type without publishing: its own save
 * or the storage's; then deletes with Publisher::forget()) → the detached records' index and
 * addresses (RecordWriter's too), RecordIndex::forget() and RecordAddresses::removed() of the
 * deleted → SaveHooks::after() and afterDelete().
 * SQL writes run in one transaction per connection; JSON-file writes cannot be rolled back if a
 * later write fails.
 *
 * Not final for 0.71 only: Folio's class of the old name extends it to take the constructor
 * arguments of before 0.71. It is final again from 0.72.
 */
class RecordDeleter
{
    /** The most ids one request may name (a list page holds 50). */
    public const int MAX_IDS = 500;

    private readonly RecordStore $store;

    /** Whether a type publishes, the Publisher decides (as for the plan); without one, none does. */
    public function __construct(
        private readonly DataManager $dm,
        private readonly ?Publisher $publisher = null,
        private readonly SaveHooks $hooks = new NoSaveHooks(),
        private readonly ?RecordIndex $index = null,
        private readonly ?RecordAddresses $addresses = null,
    ) {
        $this->store = new RecordStore($dm, $publisher, $hooks, $index, $addresses);
    }

    /**
     * What deleting these records would do. Publishing types count links in their
     * published snapshot too (restrict only), once the snapshot table exists.
     * Only ids of stored records of $type are planned: unknown ids, ids of other types
     * and repeats are ignored (a dangling pointer to an unknown id is never "detached").
     *
     * @param list<string> $ids
     */
    public function plan(string $type, array $ids): DeletePlan
    {
        $copies = new PublishedCopies($this->dm, $this->publisher);

        return $this->dm->deletePlan($type, $this->existing($type, $ids), $copies->of(...), DeletedBy::Staff);
    }

    /**
     * The given ids that name stored records of $type, in the given order, each once (one query).
     *
     * @param list<string> $ids
     * @return list<string>
     */
    private function existing(string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')));
        if ($ids === []) {
            return [];
        }
        $found = [];
        $idField = $this->dm->getTypeRegistry()->get($type)->idField;
        foreach ($this->dm->queryType($type)->whereAny([['field' => $idField, 'operator' => 'IN', 'value' => $ids]])->get()->items() as $record) {
            $found[(string) $record->getId()] = true;
        }

        return array_values(array_filter($ids, static fn (string $id): bool => isset($found[$id])));
    }

    /**
     * @param list<string> $ids
     * @param RemovalTarget|null $target where the old addresses of every deleted record (those
     *                                   deleted by cascade too) lead; null: they answer 404
     * @throws DeleteRefused restricted, or the save of a detached record was refused (nothing was written)
     */
    public function delete(string $type, array $ids, ?RemovalTarget $target = null): void
    {
        $plan = $this->plan($type, $ids);
        if ($plan->restricted()) {
            throw DeleteRefused::restricted($plan);
        }
        $target = $this->addresses !== null ? $this->addresses->finalTarget($target, array_map(static fn (array $ids): array => array_map(strval(...), $ids), $plan->deletes())) : $target;

        // Pre-phase: load and hook everything, write nothing.
        /** @var list<DynamicRecord> $saves */
        $saves = [];
        foreach ($this->detachedRecords($plan) as $record) {
            try {
                $this->hooks->before($record);
            } catch (ValidationException $e) {
                throw DeleteRefused::detachRefused($plan, $record, $e);
            }
            $saves[] = $record;
        }
        /** @var list<DynamicRecord> $deletes */
        $deletes = [];
        foreach ($plan->deletes() as $deleteType => $deleteIds) {
            foreach ($deleteIds as $id) {
                $record = $this->dm->findType($deleteType, (string) $id);
                if ($record === null) {
                    continue;
                }
                $this->hooks->beforeDelete($record);
                $deletes[] = $record;
            }
        }

        $this->transaction([...$saves, ...$deletes], function () use ($saves, $deletes): void {
            foreach ($saves as $record) {
                if ($this->publishes($record->getType()->key)) {
                    $this->publisher->saveDraft($record, null); // the working copy; the live snapshot keeps its links until the next publish
                } else {
                    $this->store->write($record);
                }
            }
            foreach ($deletes as $record) {
                $key = $record->getType()->key;
                $id = (string) $record->getId();
                $this->dm->deleteType($key, $id);
                if ($this->publishes($key)) {
                    $this->publisher->forget($key, $id);
                }
            }
        });
        foreach ($saves as $record) {
            if (!$this->publishes($record->getType()->key)) {
                $this->store->follow($record); // saveDraft() refreshed the index of a publishing one
            }
        }
        foreach ($deletes as $record) {
            $this->index?->forget($record->getType()->key, (string) $record->getId());
            $this->addresses?->removed($record->getType()->key, (string) $record->getId(), $target);
        }

        foreach ($saves as $record) {
            $this->hooks->after($record);
        }
        foreach ($deletes as $record) {
            $this->hooks->afterDelete($record);
        }
    }

    /**
     * The pointing records with the plan's ids removed, one per record (all its fields
     * applied); records that are gone or would not change are left out.
     *
     * @return list<DynamicRecord>
     */
    private function detachedRecords(DeletePlan $plan): array
    {
        /** @var array<string, array<string, array<string, list<string>>>> $grouped type => id => field => remove */
        $grouped = [];
        foreach ($plan->detaches() as $detach) {
            $grouped[$detach['type']][$detach['id']][$detach['field']] = array_values(array_unique([
                ...($grouped[$detach['type']][$detach['id']][$detach['field']] ?? []),
                ...$detach['remove'],
            ]));
        }

        $records = [];
        foreach ($grouped as $type => $byId) {
            foreach ($byId as $id => $fields) {
                $record = $this->dm->findType($type, (string) $id);
                if ($record === null) {
                    continue;
                }
                $changed = false;
                foreach ($fields as $field => $remove) {
                    $value = $record->get($field);
                    $next = DeletePlan::valueWithout($value, $remove);
                    if ($next !== $value) {
                        $record->set($field, $next);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    /**
     * Runs $writes inside one transaction per SQL connection the records live on
     * (a transaction already open there is left to its owner).
     *
     * @param list<DynamicRecord> $records
     */
    private function transaction(array $records, \Closure $writes): void
    {
        /** @var array<int, \PDO> $began */
        $began = [];
        try {
            foreach ($records as $record) {
                $driver = $this->dm->driver($record->getType()->storage);
                if (!$driver instanceof PdoDriver) {
                    continue;
                }
                $pdo = $driver->pdo();
                $key = spl_object_id($pdo);
                if (!isset($began[$key]) && !$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $began[$key] = $pdo;
                }
            }

            $writes();

            foreach ($began as $pdo) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            foreach ($began as $pdo) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            throw $e;
        }
    }

    /** @phpstan-assert-if-true !null $this->publisher */
    private function publishes(string $type): bool
    {
        return $this->store->publishes($type);
    }
}
