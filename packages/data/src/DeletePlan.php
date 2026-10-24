<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * What deleting a set of records would do across declared relations, computed
 * before anything is changed (see DataManager::deletePlan()).
 *
 * - deletes: every record that goes, per type: the roots plus everything reached
 *   through "cascade" relations, recursively.
 * - detaches: pointing records ("detach" relations) that lose ids from one field.
 * - restrictions: per type, how many records ("restrict" relations, copies included)
 *   still point at a record being deleted. Any restriction blocks the whole plan.
 */
final readonly class DeletePlan
{
    /**
     * @param array<string, list<string>> $deletes type => ids (roots included)
     * @param list<array{type: string, id: string, field: string, remove: list<string>}> $detaches
     * @param array<string, int> $restrictions type => count of pointing records
     * @param list<string> $rootIds the ids the plan was asked for (of $rootType)
     */
    public function __construct(
        private array $deletes,
        private array $detaches,
        private array $restrictions,
        public string $rootType,
        public array $rootIds,
    ) {}

    /** @return array<string, list<string>> */
    public function deletes(): array
    {
        return $this->deletes;
    }

    /** @return list<array{type: string, id: string, field: string, remove: list<string>}> */
    public function detaches(): array
    {
        return $this->detaches;
    }

    /** @return array<string, int> */
    public function restrictions(): array
    {
        return $this->restrictions;
    }

    public function restricted(): bool
    {
        return $this->restrictions !== [];
    }

    /**
     * Counts per type for a confirmation: deleted records excluding the roots,
     * distinct detached records, restricting records.
     *
     * @return array{deleted: array<string, int>, detached: array<string, int>, restricted: array<string, int>}
     */
    public function counts(): array
    {
        $deleted = [];
        foreach ($this->deletes as $type => $ids) {
            $count = count($type === $this->rootType ? array_diff($ids, $this->rootIds) : $ids);
            if ($count > 0) {
                $deleted[$type] = $count;
            }
        }

        $records = [];
        foreach ($this->detaches as $detach) {
            $records[$detach['type']][$detach['id']] = true;
        }

        return [
            'deleted' => $deleted,
            'detached' => array_map('count', $records),
            'restricted' => $this->restrictions,
        ];
    }

    /**
     * A stored relation value with $remove taken out, as a detach writes it: a
     * multiple relation stays a JSON array string in its order, a single id becomes ''.
     * For executors that save detached records themselves.
     *
     * @param list<string> $remove
     */
    public static function valueWithout(mixed $value, array $remove): mixed
    {
        return DeletePlanner::without($value, $remove);
    }
}
