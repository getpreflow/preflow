<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Validation\ValidationException;

/**
 * The one write path for records: an admin's form, its autosave and bulk actions, a translation
 * run and an app's own code save through it and get the same steps.
 *
 * A save, in order:
 * 1. SaveHooks::before() (a ValidationException stops the save before anything is stored);
 * 2. a type that publishes: the schedule rule (a save by someone who may not publish cancels a
 *    pending _publish_at, else SaveOptions::$publishAt applies), the working copy
 *    (Publisher::saveDraft(), no rules), then Publisher::publish() when asked. SaveHooks::after()
 *    runs once the working copy is stored, also when the publish fails (its exception is rethrown);
 * 3. any other type: RecordChecks::validate(), the type's own save (SaveHooks::replacesSave()) or
 *    the storage's, RecordIndex::refresh(), RecordAddresses::sync() (did an address leave the
 *    site?), SaveHooks::after().
 *
 * A failed write (ValidationException, VersionConflict) propagates to the caller, who shows it.
 */
final class RecordWriter
{
    /** The manual list position (RecordTypes::orderable()). */
    public const string ORDER = '_order';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    private readonly RecordStore $store;

    public function __construct(
        private readonly DataManager $dm,
        private readonly Publisher $publisher,
        private readonly RecordChecks $checks,
        private readonly SaveHooks $hooks = new NoSaveHooks(),
        private readonly RecordTypes $types = new NoRecordTypes(),
        private readonly ?RecordIndex $index = null,
        private readonly ?RecordAddresses $addresses = null,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
        $this->store = new RecordStore($dm, $publisher, $hooks, $index, $addresses);
    }

    /**
     * Saves a new record: an empty id gets a random one (32 hex characters), a record of an
     * orderable type goes last (_order = the highest + 10). Then as save().
     *
     * @throws \InvalidArgumentException when a record with the given id exists (nothing written)
     * @throws ValidationException
     * @throws VersionConflict
     */
    public function create(DynamicRecord $record, SaveOptions $options = new SaveOptions()): SaveOutcome
    {
        $def = $record->getType();
        $id = (string) $record->getId();
        if ($id === '') {
            $record->set($def->idField, bin2hex(random_bytes(16)));
        } elseif ($this->dm->findType($def->key, $id) !== null) {
            throw new \InvalidArgumentException("A {$def->key} with the id \"{$id}\" exists already.");
        }
        if ($this->types->orderable($def->key)) {
            $record->set(self::ORDER, $this->maxOrder($def->key) + 10);
        }

        return $this->save($record, $options);
    }

    /**
     * Saves the record, new or changed, in the order the class describes.
     *
     * @throws ValidationException from SaveHooks::before() (nothing stored), a draft the storage
     *                             refuses (nothing stored), the checks of a type that does not
     *                             publish (nothing stored), or a failed publish (the draft is stored)
     * @throws VersionConflict
     *
     * When the publish fails and SaveHooks::after() then throws too, the publish's exception is
     * thrown and the hook's is dropped: an exception already thrown cannot take another as its
     * previous one, and the publish failure is what the caller must show.
     */
    public function save(DynamicRecord $record, SaveOptions $options = new SaveOptions()): SaveOutcome
    {
        $this->hooks->before($record);
        $type = $record->getType()->key;

        if ($this->publisher->enabled($type)) {
            $this->schedule($record, $options);
            $this->publisher->saveDraft($record, $options->loadedVersion);
            if ($options->publish) {
                try {
                    // Checked against the version the draft save just wrote: a save landing in between conflicts.
                    $this->publisher->publish($record, $options->by, (int) $record->get('_version'));
                } catch (\Throwable $e) {
                    try {
                        $this->hooks->after($record); // the working copy was saved
                    } catch (\Throwable) {
                        // The publish failure wins (see above).
                    }
                    throw $e;
                }
            }
            $this->hooks->after($record);

            return new SaveOutcome($record, false);
        }

        $this->checks->validate($record);
        $this->store->write($record);
        $left = $this->store->follow($record);
        $this->hooks->after($record);

        return new SaveOutcome($record, $left);
    }

    /**
     * Sets one field and saves the record (a bulk "set"): as save().
     *
     * @throws \InvalidArgumentException when the type has no such field
     * @throws ValidationException
     * @throws VersionConflict
     */
    public function setField(DynamicRecord $record, string $field, mixed $value, SaveOptions $options = new SaveOptions()): SaveOutcome
    {
        $def = $record->getType();
        if (!isset($def->fields[$field]) || $field === $def->idField) {
            throw new \InvalidArgumentException("Type \"{$def->key}\" has no field \"{$field}\" to set.");
        }
        $record->set($field, $value);

        return $this->save($record, $options);
    }

    /**
     * Moves a record of an orderable type one step up or down its list (by _order, then id): it
     * swaps positions with its neighbour. Both are saved through the hooks (before, the type's own
     * save, after), without content checks and without a new version (a position is not content),
     * and a publishing type carries the positions into its snapshots (Publisher::syncOrder()).
     * Records without a position yet are numbered in their current order first (10, 20, ...).
     *
     * @param 'up'|'down' $direction
     * @return bool false when nothing moved: the record is first (up) or last (down)
     * @throws \InvalidArgumentException when the type is not orderable, the direction is neither, or
     *                                   the type has no record with this id
     * @throws ValidationException from SaveHooks::before() of either record (nothing stored)
     */
    public function reorder(string $type, string $id, string $direction): bool
    {
        if (!$this->types->orderable($type)) {
            throw new \InvalidArgumentException("Type \"{$type}\" is not orderable.");
        }
        if ($direction !== 'up' && $direction !== 'down') {
            throw new \InvalidArgumentException("A record moves 'up' or 'down', not \"{$direction}\".");
        }

        $idField = $this->dm->getTypeRegistry()?->get($type)->idField ?? throw new \LogicException('RecordWriter needs a TypeRegistry.');
        // Numeric: SQL-stored types keep positions in TEXT columns.
        $records = array_values($this->dm->queryType($type)->orderBy(self::ORDER, numeric: true)->orderBy($idField)->get()->items());
        $index = null;
        foreach ($records as $i => $record) {
            if ((string) $record->getId() === $id) {
                $index = $i;
            }
        }
        if ($index === null) {
            throw new \InvalidArgumentException("Type \"{$type}\" has no record \"{$id}\" to move.");
        }
        $neighbour = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($records[$neighbour])) {
            return false;
        }

        // Numeric, not int: SQL drivers return positions as text.
        $numbered = array_any($records, static fn (DynamicRecord $r): bool => !is_numeric($r->get(self::ORDER))) ? $records : [];
        foreach ($numbered as $i => $record) {
            $record->set(self::ORDER, ($i + 1) * 10);
        }
        $a = $records[$index];
        $b = $records[$neighbour];
        $orderA = $a->get(self::ORDER);
        $a->set(self::ORDER, $b->get(self::ORDER));
        $b->set(self::ORDER, $orderA);

        $this->hooks->before($a);
        $this->hooks->before($b);
        foreach ($numbered as $record) {
            if ($record !== $a && $record !== $b) {
                $this->storePosition($record);
            }
        }
        $this->storePosition($a);
        $this->storePosition($b);
        $this->hooks->after($a);
        $this->hooks->after($b);

        return true;
    }

    /** A new position: stored without a new version, and carried into the snapshot of a publishing type. */
    private function storePosition(DynamicRecord $record): void
    {
        $this->store->write($record);
        if ($this->publisher->enabled($record->getType()->key)) {
            $this->publisher->syncOrder($record);
        }
    }

    /**
     * The schedule rule of a publishing type: a save by someone who may not publish cancels a
     * pending schedule; else $publishAt sets it ('' rather than null: SQL drivers skip null values).
     */
    private function schedule(DynamicRecord $record, SaveOptions $options): void
    {
        if (!$options->mayPublish) {
            $record->set('_publish_at', '');
        } elseif ($options->publishAt !== null) {
            $record->set('_publish_at', $this->publishAt($options->publishAt));
        }
    }

    /** A valid future "Y-m-d\TH:i" (datetime-local) in the app's zone, else '' (cleared). */
    private function publishAt(string $value): string
    {
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);

        return $at !== false && $at->format('Y-m-d\TH:i') === $value && $at > ($this->now)() ? $value : '';
    }

    private function maxOrder(string $type): int
    {
        $max = 0;
        foreach ($this->dm->queryType($type)->get()->items() as $record) {
            $max = max($max, (int) $record->get(self::ORDER));
        }

        return $max;
    }
}
