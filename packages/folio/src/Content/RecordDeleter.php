<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DeletePlan;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\Schema;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Extension\AfterDelete;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\BeforeDelete;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\ShadowTypes;
use Preflow\Validation\ValidationException;

/**
 * Folio's delete: through the data package's delete plan, executed record by record
 * on Folio's own save and delete paths, so a cascaded record goes exactly like a
 * single admin delete and a detach is a save of the pointing record.
 *
 * delete() computes the plan itself, right before executing it: a plan shown to the
 * admin in an earlier request (summary()) is never executed.
 *
 * Order: plan → refused if restricted → #[BeforeSave] on every detached record (the
 * ids already removed) and #[BeforeDelete] on every record to delete, all before any
 * write (a Refused or ValidationException cancels everything) → the writes (detaches
 * via saveDraft or saveType, then deletes with Publisher::forget) → #[AfterSave] and
 * #[AfterDelete]. SQL writes run in one transaction per connection; JSON-file writes
 * cannot be rolled back if a later write fails.
 */
final class RecordDeleter
{
    /**
     * @param (\Closure(class-string, DynamicRecord): void)|null $hooks runs a type's hooks
     *        (Customizations::runHooks); null: no hooks
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly ?Publisher $publisher = null,
        private readonly ?\Closure $hooks = null,
    ) {}

    /**
     * What deleting these records would do. Publishing types count links in their
     * published snapshot too (restrict only), once the snapshot table exists.
     *
     * @param list<string> $ids
     */
    public function plan(string $type, array $ids): DeletePlan
    {
        return $this->dm->deletePlan($type, $ids, fn (TypeDefinition $def): array => $this->copies($def));
    }

    /**
     * @param list<string> $ids
     * @throws Refused restricted, or a hook refused (nothing was written)
     */
    public function delete(string $type, array $ids): void
    {
        $plan = $this->plan($type, $ids);
        if ($plan->restricted()) {
            throw new Refused($this->restrictedText($plan));
        }

        // Pre-phase: load and hook everything, write nothing.
        /** @var list<DynamicRecord> $saves */
        $saves = [];
        foreach ($this->detachedRecords($plan) as $record) {
            try {
                $this->hook(BeforeSave::class, $record);
            } catch (ValidationException $e) {
                throw new Refused(sprintf(
                    "Can't delete: one %s that links here can't be saved without the link. %s",
                    $this->noun($record->getType()->key, 1),
                    implode(' ', self::messages($e->errors())),
                ), 0, $e);
            }
            $saves[] = $record;
        }
        /** @var list<DynamicRecord> $deletes */
        $deletes = [];
        foreach ($plan->deletes() as $deleteType => $deleteIds) {
            foreach ($deleteIds as $id) {
                $record = $this->dm->findType($deleteType, $id);
                if ($record === null) {
                    continue;
                }
                $this->hook(BeforeDelete::class, $record);
                $deletes[] = $record;
            }
        }

        $this->transaction([...$saves, ...$deletes], function () use ($saves, $deletes): void {
            foreach ($saves as $record) {
                if ($this->publishes($record->getType()->key)) {
                    $this->publisher->saveDraft($record, null); // the working copy; the live snapshot keeps its links until the next publish
                } else {
                    $this->dm->saveType($record, false);
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
            $this->hook(AfterSave::class, $record);
        }
        foreach ($deletes as $record) {
            $this->hook(AfterDelete::class, $record);
        }
    }

    /**
     * The confirmation for a plan: "This can't be undone." and a line per type of
     * what else happens; a restricted plan gives the refusal instead.
     *
     * @return array{restricted: bool, lines: list<string>, confirm: string}
     */
    public function summary(DeletePlan $plan): array
    {
        if ($plan->restricted()) {
            $text = $this->restrictedText($plan);

            return ['restricted' => true, 'lines' => [$text], 'confirm' => $text];
        }

        $counts = $plan->counts();
        $lines = [];
        foreach ($counts['deleted'] as $type => $n) {
            $lines[] = sprintf('Also deletes %d %s.', $n, $this->noun($type, $n));
        }
        foreach ($counts['detached'] as $type => $n) {
            $lines[] = sprintf('%d %s %s a link.', $n, $this->noun($type, $n), $n === 1 ? 'loses' : 'lose');
        }

        return ['restricted' => false, 'lines' => $lines, 'confirm' => implode("\n", ["This can't be undone.", ...$lines])];
    }

    private function restrictedText(DeletePlan $plan): string
    {
        $parts = [];
        foreach ($plan->restrictions() as $type => $n) {
            $parts[] = sprintf('%d %s', $n, $this->noun($type, $n));
        }
        $total = array_sum($plan->restrictions());

        return sprintf("Can't delete: %s still %s here.", self::join($parts), $total === 1 ? 'points' : 'point');
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

    /**
     * The published snapshot of a publishing type, once its storage exists (on SQL the
     * table is made by `preflow migrate`; a JSON directory that isn't there has no rows).
     *
     * @return list<TypeDefinition>
     */
    private function copies(TypeDefinition $def): array
    {
        if (!$this->publishes($def->key)) {
            return [];
        }
        $shadow = ShadowTypes::published($def);
        $driver = $this->dm->driver($def->storage);
        if ($driver instanceof PdoDriver && !(new Schema($driver->pdo()))->hasTable($shadow->table)) {
            return [];
        }

        return [$shadow];
    }

    /** @phpstan-assert-if-true !null $this->publisher */
    private function publishes(string $type): bool
    {
        return $this->publisher !== null && $this->publisher->enabled($type);
    }

    /** @param class-string $hook */
    private function hook(string $hook, DynamicRecord $record): void
    {
        if ($this->hooks !== null) {
            ($this->hooks)($hook, $record);
        }
    }

    /**
     * The type's label as a noun for $n records: "games", "1 game"; a label starting
     * with an acronym keeps its case ("FAQ entries").
     */
    private function noun(string $type, int $n): string
    {
        $label = $this->catalog->get($type)?->label ?? ucfirst($type);
        if (!preg_match('/^\p{Lu}\p{Lu}/u', $label)) {
            $label = mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1);
        }
        if ($n !== 1) {
            return $label;
        }

        return match (true) {
            (bool) preg_match('/ies$/u', $label) => mb_substr($label, 0, -3) . 'y',
            (bool) preg_match('/(ss|us|is)$/u', $label) => $label,
            (bool) preg_match('/s$/u', $label) => mb_substr($label, 0, -1),
            default => $label,
        };
    }

    /** @param list<string> $parts "a", "a and b", "a, b and c" */
    private static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts) . ' and ' . $last;
    }

    /**
     * @param array<mixed> $errors
     * @return list<string>
     */
    private static function messages(array $errors): array
    {
        $messages = [];
        array_walk_recursive($errors, static function (mixed $message) use (&$messages): void {
            if (is_string($message)) {
                $messages[] = $message;
            }
        });

        return $messages;
    }
}
