<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Driver\PdoDriver;

/**
 * Works out and carries out delete plans (see DataManager::deletePlan() and
 * DataManager::executeDeletePlan()).
 *
 * @internal use the DataManager methods
 */
final class DeletePlanner
{
    /** Ids per pointer query; each id binds twice in SQL HAS. */
    private const CHUNK = 400;

    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeRegistry $registry,
    ) {}

    /**
     * @param list<string> $ids
     * @param (\Closure(TypeDefinition): list<TypeDefinition>)|null $copies
     * @param DeletedBy $by whose rule applies: onDelete (Staff) or onSelfDelete (Self)
     */
    public function plan(string $type, array $ids, ?\Closure $copies = null, DeletedBy $by = DeletedBy::Staff): DeletePlan
    {
        $this->registry->get($type); // unknown root type is an error
        $roots = $this->unique(array_filter(array_map('strval', $ids), static fn (string $id) => $id !== ''));

        /** @var array<string, array<string, true>> $deletes type => id => true, in discovery order */
        $deletes = [$type => array_fill_keys($roots, true)];

        // 1. Follow cascades until no new records turn up (cycles end there).
        $queue = $roots === [] ? [] : [[$type, $roots]];
        while ($queue !== []) {
            [$target, $newIds] = array_shift($queue);
            foreach ($this->dm->relationsTo($target) as $relation) {
                if (self::rule($relation, $by) !== OnDelete::Cascade) {
                    continue;
                }
                $found = [];
                foreach ($this->pointers($this->registry->get($relation->type), $relation->field, $newIds) as $id => $_) {
                    if (!isset($deletes[$relation->type][$id])) {
                        $deletes[$relation->type][$id] = true;
                        $found[] = $id;
                    }
                }
                if ($found !== []) {
                    $queue[] = [$relation->type, $found];
                }
            }
        }

        // 2. With the full set known, find detaches and restrictions. Records being
        //    deleted themselves never count as pointers.
        $detaches = [];
        /** @var array<string, array<string, array<string, true>>> $restricting type => table => id => true */
        $restricting = [];
        $copyCache = [];
        foreach ($deletes as $target => $idSet) {
            $targetIds = array_map('strval', array_keys($idSet));
            foreach ($this->dm->relationsTo($target) as $relation) {
                if (self::rule($relation, $by) === OnDelete::Cascade) {
                    continue; // already part of $deletes
                }
                $def = $this->registry->get($relation->type);
                $doomed = $deletes[$relation->type] ?? [];

                if (self::rule($relation, $by) === OnDelete::Restrict) {
                    $copyCache[$relation->type] ??= $copies === null ? [] : $copies($def);
                    foreach ([$def, ...$copyCache[$relation->type]] as $source) {
                        foreach ($this->pointers($source, $relation->field, $targetIds) as $id => $_) {
                            if (!isset($doomed[$id])) {
                                $restricting[$relation->type][$source->table][$id] = true;
                            }
                        }
                    }
                    continue;
                }

                foreach ($this->pointers($def, $relation->field, $targetIds) as $id => $value) {
                    if (isset($doomed[$id])) {
                        continue;
                    }
                    $remove = array_values(array_intersect(self::heldIds($value), $targetIds));
                    if ($remove !== []) {
                        $detaches[] = ['type' => $relation->type, 'id' => (string) $id, 'field' => $relation->field, 'remove' => $remove];
                    }
                }
            }
        }

        $restrictions = [];
        foreach ($restricting as $pointerType => $tables) {
            $restrictions[$pointerType] = array_sum(array_map('count', $tables));
        }

        return new DeletePlan(
            deletes: array_map(static fn (array $set) => array_map('strval', array_keys($set)), $deletes),
            detaches: $detaches,
            restrictions: $restrictions,
            rootType: $type,
            rootIds: $roots,
        );
    }

    private static function rule(Relation $relation, DeletedBy $by): OnDelete
    {
        return $by === DeletedBy::Self ? $relation->onSelfDelete : $relation->onDelete;
    }

    /**
     * Carries out a plan: detaches first, then deletes (roots first, then cascades).
     *
     * One transaction is begun per PDO connection involved (unless one is already
     * open there; then the caller owns it) and all are committed at the end or
     * rolled back on any error. Writes to JSON-file storage are not transactional
     * and are NOT undone when a later step fails.
     */
    public function execute(DeletePlan $plan): void
    {
        if ($plan->restricted()) {
            throw new \RuntimeException(sprintf(
                'Delete refused: records still point here (%s).',
                implode(', ', array_map(
                    static fn (string $type, int $n) => "{$type}: {$n}",
                    array_keys($plan->restrictions()),
                    $plan->restrictions(),
                )),
            ));
        }

        $types = array_unique([
            ...array_keys($plan->deletes()),
            ...array_map(static fn (array $d) => $d['type'], $plan->detaches()),
        ]);
        $defs = [];
        foreach ($types as $type) {
            $defs[$type] = $this->registry->get($type);
        }

        /** @var array<int, \PDO> $began */
        $began = [];
        try {
            foreach ($defs as $def) {
                $driver = $this->dm->driver($def->storage);
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

            foreach ($plan->detaches() as $detach) {
                $record = $this->dm->findIn($defs[$detach['type']], $detach['id']);
                if ($record === null) {
                    continue;
                }
                $value = $record->get($detach['field']);
                $next = self::without($value, $detach['remove']);
                if ($next !== $value) {
                    $record->set($detach['field'], $next);
                    $this->dm->saveType($record, validate: false);
                }
            }

            foreach ($plan->deletes() as $type => $ids) {
                foreach ($ids as $id) {
                    $this->dm->deleteIn($defs[$type], $id);
                }
            }

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

    /**
     * Records of $def whose $field points at any of $ids.
     *
     * @param list<string> $ids
     * @return array<string, mixed> id => stored field value
     */
    private function pointers(TypeDefinition $def, string $field, array $ids): array
    {
        $found = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->dm->queryIn($def)
                ->whereAny([['field' => $field, 'operator' => 'HAS', 'value' => $chunk]])
                ->get()
                ->items();
            foreach ($rows as $row) {
                /** @var DynamicRecord $row */
                $found[(string) $row->getId()] = $row->get($field);
            }
        }

        return $found;
    }

    /**
     * Ids held by a stored relation value: a JSON array string (multiple), an
     * array, or a single id string.
     *
     * @return list<string>
     */
    private static function heldIds(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = str_starts_with(ltrim($value), '[') ? json_decode($value, true) : null;
            if (!is_array($decoded)) {
                return $value === '' ? [] : [$value];
            }
            $value = $decoded;
        }
        if (!is_array($value)) {
            return is_scalar($value) && (string) $value !== '' ? [(string) $value] : [];
        }

        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }

    /**
     * The stored value with $remove taken out: a list keeps its order and stays a
     * JSON array string (as RelationFieldType::toStorage writes it); a single id
     * becomes ''. Public for DeletePlan::valueWithout().
     *
     * @param list<string> $remove
     */
    public static function without(mixed $value, array $remove): mixed
    {
        $isList = is_array($value)
            || (is_string($value) && str_starts_with(ltrim($value), '[') && is_array(json_decode($value, true)));
        if ($isList) {
            $held = self::heldIds($value);
            $kept = array_values(array_filter($held, static fn (string $id) => !in_array($id, $remove, true)));
            if ($kept === $held && is_string($value)) {
                return $value; // nothing to remove: leave the stored text untouched
            }

            return json_encode($kept, JSON_UNESCAPED_SLASHES);
        }

        return in_array(self::heldIds($value)[0] ?? null, $remove, true) ? '' : $value;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function unique(array $ids): array
    {
        return array_values(array_unique($ids));
    }
}
