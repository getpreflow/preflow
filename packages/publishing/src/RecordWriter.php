<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\Attributes\Timestamps;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\LocaleValue;
use Preflow\Data\ModelMetadata;
use Preflow\Data\Slugs;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * The one write path for records: an admin's form, its autosave and bulk actions, a translation
 * run and an app's own code save through it and get the same steps.
 *
 * A save, in order (the hooks get SaveOptions::$kind):
 * 1. SaveHooks::before() (a ValidationException stops the save before anything is stored);
 * 2. a type that publishes: the schedule rule (a save by someone who may not publish cancels a
 *    pending _publish_at, else SaveOptions::$publishAt applies), the working copy
 *    (Publisher::saveDraft(), no rules), then Publisher::publish() when asked. SaveHooks::after()
 *    runs once the working copy is stored, also when the publish fails (then PublishFailed is
 *    thrown, with the stored draft);
 * 3. any other type: RecordChecks::validate(), the type's own save (SaveHooks::replacesSave()) or
 *    the storage's, RecordIndex::refresh(), RecordAddresses::sync() (did an address leave the
 *    site?), SaveHooks::after().
 *
 * A failed write (ValidationException, VersionConflict: nothing stored; PublishFailed: the draft
 * stored, its publish refused) propagates to the caller, who shows it.
 *
 * Whether a type publishes, the Publisher decides; without one, none does.
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
        private readonly ?Publisher $publisher,
        private readonly RecordChecks $checks,
        private readonly SaveHooks $hooks = new NoSaveHooks(),
        private readonly RecordTypes $types = new NoRecordTypes(),
        private readonly ?RecordIndex $index = null,
        private readonly ?RecordAddresses $addresses = null,
        ?\Closure $now = null,
        private readonly ?LoggerInterface $logger = null,
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
     * Saves a copy of a record as a new record, through save() (SaveHooks get WriteKind::Save,
     * whatever $options->kind says), and returns it.
     *
     * The copy has the record's values in every language, its blocks and matrix rows (they are
     * part of its values, so the copy owns its own), and the same references to assets and other
     * records. What makes a record one of a kind is new:
     * - a new id (32 hex characters);
     * - the address takes a free one, unique or not: the fields of the type's address
     *   (RecordAddressFields, when the RecordTypes binding implements it: a route's "{handle}"),
     *   a field named "slug" and a field with the "slug" rule. A slug takes the first free one by
     *   Slugs::unique() ("about" → "about-2"); a catch-all ("{...path}", or the "slug:path" rule)
     *   takes the "-2", "-3"... suffix on the whole path ("docs/ueber-uns" → "docs/ueber-uns-2");
     *   a translatable one takes it in each language;
     * - any other unique field of free text (string or text, not translatable, without an email,
     *   url, numeric, integer, in or regex rule) takes the same "-2", "-3"... suffix, within its
     *   length; every other unique field (a number, a date, an email, a relation, a translatable
     *   one) is left empty, since no suffix keeps it valid. A value is free when no other record of
     *   the type holds it at all: the fields that scope a unique field are not looked at, so a
     *   copy's value is free across the whole table, not only within its scope;
     * - system keys are those of a new record: no _version, schedule (_publish_at), share token
     *   or publish stamps, and a model class with #[Timestamps] loses its created and updated
     *   values (a SaveHooks implementation that stamps them, or an author, runs as for a new record);
     * - an orderable type's copy is placed right after the record (between it and the next, or the
     *   list renumbered 10, 20... when there is no room, as reorder() numbers it);
     * - a type that publishes gets a draft that has never been live: nothing is published, no
     *   revision is copied, and the record itself stays as it is.
     *
     * $options->by and $options->mayPublish apply as to save(); a copy is never published or
     * scheduled with it.
     *
     * @since 0.75
     *
     * @throws \InvalidArgumentException when the type has no record with this id, or $options asks to publish (nothing written)
     * @throws ValidationException        from SaveHooks::before(), the storage or the checks of a type that does not publish (nothing stored)
     * @throws VersionConflict
     */
    public function duplicate(string $type, string $id, SaveOptions $options = new SaveOptions()): SaveOutcome
    {
        if ($options->publish) {
            throw new \InvalidArgumentException('A copy starts as a draft: publish it with save() once it is checked.');
        }
        $source = $this->dm->findType($type, $id) ?? throw new \InvalidArgumentException("Type \"{$type}\" has no record \"{$id}\" to copy.");
        $def = $source->getType();

        $data = array_diff_key($source->toArray(), array_flip([...Publisher::META, '_published_at', '_published_by', ...$this->timestamps($def)]));
        $data[$def->idField] = bin2hex(random_bytes(16));
        $copy = DynamicRecord::fromArray($def, $data);
        $address = $this->types instanceof RecordAddressFields ? $this->types->addressFields($def->key) : [];
        foreach ($def->fields as $name => $field) {
            if ($name !== $def->idField && $copy->get($name) !== null) {
                $this->freeValue($copy, $field, $address);
            }
        }

        $renumber = null;
        if ($this->types->orderable($def->key)) {
            $renumber = $this->placeAfter($source, $copy);
        }

        $outcome = $this->save($copy, new SaveOptions(by: $options->by, mayPublish: $options->mayPublish, kind: WriteKind::Save));
        if ($renumber !== null) {
            foreach ($renumber as $i => $record) {
                $position = ($i + 1) * 10;
                $record ??= $copy;
                if (!is_numeric($record->get(self::ORDER)) || (int) $record->get(self::ORDER) !== $position) {
                    $record->set(self::ORDER, $position);
                    $this->storePosition($record);
                }
            }
        }

        return $outcome;
    }

    /**
     * The created and updated fields of a model class with #[Timestamps] (Timestamps::CREATED_NAMES,
     * UPDATED_NAMES), which a copy does not take over.
     *
     * @return list<string>
     */
    private function timestamps(TypeDefinition $def): array
    {
        $class = $this->dm->getTypeRegistry()?->classFor($def->key);
        if ($class === null || !ModelMetadata::for($class)->hasTimestamps) {
            return [];
        }

        return array_values(array_intersect([...Timestamps::CREATED_NAMES, ...Timestamps::UPDATED_NAMES], array_keys($def->fields)));
    }

    /**
     * A copy's value of an address, slug or unique field: a free one, or none (see duplicate()).
     *
     * @param array<string, bool> $address the type's address fields (RecordAddressFields): name => catch-all
     */
    private function freeValue(DynamicRecord $copy, TypeFieldDefinition $field, array $address): void
    {
        $def = $copy->getType();
        $name = $field->name;
        $value = $copy->get($name);
        $validate = array_map(static fn (string $rule): string => strtolower(str_replace(' ', '', $rule)), array_filter($field->validate, 'is_string'));
        $rules = array_map(static fn (string $rule): string => explode(':', $rule, 2)[0], $validate);
        $path = ($address[$name] ?? false) || in_array('slug:path', $validate, true);
        $slug = isset($address[$name]) || $name === 'slug' || in_array('slug', $rules, true);
        $translatable = in_array($name, $def->translatableFields(), true);
        $max = $field->length ?? FieldKinds::DEFAULT_LENGTH;

        if ($slug && $translatable) {
            $copy->set($name, $this->freeSlugs($def, $name, LocaleValue::map($value, $def->locales), $max, $path));

            return;
        }
        if ($slug) {
            if (is_string($value) && $value !== '') {
                $copy->set($name, $path ? $this->suffixed($def, $name, $value, $max) : Slugs::unique($this->dm, $def->key, $value, $name, max: $max));
            }

            return;
        }
        if ($field->unique === false) {
            return;
        }
        $suffixable = !$translatable
            && $this->dm->fieldKinds()->isText($field->type)
            && array_intersect($rules, ['email', 'url', 'numeric', 'integer', 'in', 'regex']) === [];
        $copy->set($name, $suffixable && is_string($value) && $value !== '' ? $this->suffixed($def, $name, $value, $max) : null);
    }

    /**
     * A translatable address's free value per language, against what the type's other records
     * hold in that language: Slugs::among() for a slug, the "-2", "-3"... suffix for a path.
     * It reads every record of the type (a translatable value is a locale map, which a query
     * cannot look up per language); a copy is made by hand, one at a time.
     *
     * @param array<string, mixed> $values locale => value
     * @return array<string, mixed>
     */
    private function freeSlugs(TypeDefinition $def, string $name, array $values, int $max, bool $path): array
    {
        $taken = [];
        foreach ($this->dm->queryType($def->key)->get()->items() as $record) {
            foreach (LocaleValue::map($record->get($name), $def->locales) as $locale => $slug) {
                if (is_string($slug) && $slug !== '') {
                    $taken[$locale][$slug] = true;
                }
            }
        }
        foreach ($values as $locale => $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            $inLocale = $taken[$locale] ?? [];
            if (!$path) {
                $values[$locale] = Slugs::among($slug, $inLocale, $max);
                continue;
            }
            for ($n = 2; isset($inLocale[$candidate = self::withSuffix($slug, $n, $max)]); $n++) {
            }
            $values[$locale] = $candidate;
        }

        return $values;
    }

    /** $value with the first free "-2", "-3"... suffix in the field, the whole within $max characters. */
    private function suffixed(TypeDefinition $def, string $field, string $value, int $max): string
    {
        for ($n = 2; ; $n++) {
            $candidate = self::withSuffix($value, $n, $max);
            if ($this->dm->queryType($def->key)->where($field, $candidate)->first() === null) {
                return $candidate;
            }
        }
    }

    private static function withSuffix(string $value, int $n, int $max): string
    {
        $suffix = '-' . $n;

        return mb_substr($value, 0, max(1, $max - mb_strlen($suffix))) . $suffix;
    }

    /**
     * Gives the copy of an orderable record the position right after it: halfway to the next
     * record when there is room. Else the list must be renumbered once the copy is saved: the
     * order to number, the source and null (the copy) in their places.
     *
     * @return list<DynamicRecord|null>|null
     */
    private function placeAfter(DynamicRecord $source, DynamicRecord $copy): ?array
    {
        $def = $source->getType();
        // As reorder() reads them: by position (numeric: SQL keeps it as text), then id.
        $records = array_values($this->dm->queryType($def->key)->orderBy(self::ORDER, numeric: true)->orderBy($def->idField)->get()->items());
        $index = null;
        foreach ($records as $i => $record) {
            if ((string) $record->getId() === (string) $source->getId()) {
                $index = $i;
                $records[$i] = $source;
            }
        }
        $here = $source->get(self::ORDER);
        $next = $index === null ? null : ($records[$index + 1] ?? null)?->get(self::ORDER);
        $numbered = !array_any($records, static fn (DynamicRecord $r): bool => !is_numeric($r->get(self::ORDER)));
        if ($numbered && is_numeric($here) && ($next === null || (int) $next - (int) $here >= 2)) {
            $copy->set(self::ORDER, $next === null ? (int) $here + 10 : (int) $here + intdiv((int) $next - (int) $here, 2));

            return null;
        }

        $copy->set(self::ORDER, is_numeric($here) ? (int) $here : 0);
        array_splice($records, ($index ?? count($records) - 1) + 1, 0, [null]);

        return $records;
    }

    /**
     * Saves the record, new or changed, in the order the class describes.
     *
     * @throws ValidationException from SaveHooks::before(), a draft the storage refuses, or the
     *                             checks of a type that does not publish: nothing stored (one
     *                             from SaveHooks::after() comes after the store)
     * @throws VersionConflict     the draft's $loadedVersion is stale: nothing stored
     * @throws PublishFailed       the draft is stored and SaveHooks::after() has run, but the
     *                             publish failed: its checks refused (a ValidationException) or a
     *                             save landed after the draft (a VersionConflict), in getPrevious()
     *
     * When the publish fails and SaveHooks::after() then throws too, PublishFailed is thrown and
     * the hook's exception is logged (a warning, with a logger): the publish failure is what the
     * caller must show.
     */
    public function save(DynamicRecord $record, SaveOptions $options = new SaveOptions()): SaveOutcome
    {
        $this->hooks->before($record, $options->kind);

        if ($this->publishes($record->getType()->key)) {
            $schedule = $this->schedule($options);
            if ($schedule !== null) {
                $record->set('_publish_at', $schedule);
            }
            $this->publisher->saveDraft($record, $options->loadedVersion);
            if ($options->publish) {
                try {
                    // Checked against the version the draft save just wrote: a save landing in between conflicts.
                    $this->publisher->publish($record, $options->by, (int) $record->get('_version'));
                } catch (\Throwable $e) {
                    try {
                        $this->hooks->after($record, $options->kind); // the working copy was saved
                    } catch (\Throwable $hookFailure) {
                        // The publish failure wins (see above).
                        $this->logger?->warning("RecordWriter: SaveHooks::after() failed after a failed publish of {$record->getType()->key} {$record->getId()}", ['exception' => $hookFailure]);
                    }
                    throw $e instanceof ValidationException || $e instanceof VersionConflict
                        ? new PublishFailed(new SaveOutcome($record, false), $e)
                        : $e;
                }
            }
            $this->hooks->after($record, $options->kind);

            return new SaveOutcome($record, false);
        }

        $this->checks->validate($record);
        $this->store->write($record);
        $left = $this->store->follow($record);
        $this->hooks->after($record, $options->kind);

        return new SaveOutcome($record, $left);
    }

    /**
     * Sets one field and saves the record (a bulk "set", SaveOptions::$kind Bulk): as save().
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
     * save, after; WriteKind::Reorder), without content checks and without a new version (a position is not content),
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

        $this->hooks->before($a, WriteKind::Reorder);
        $this->hooks->before($b, WriteKind::Reorder);
        foreach ($numbered as $record) {
            if ($record !== $a && $record !== $b) {
                $this->storePosition($record);
            }
        }
        $this->storePosition($a);
        $this->storePosition($b);
        $this->hooks->after($a, WriteKind::Reorder);
        $this->hooks->after($b, WriteKind::Reorder);

        return true;
    }

    /**
     * Copies a revision into the working copy of a publishing type (Publisher::restore()), under
     * the schedule rule: restored by someone who may not publish, a pending schedule is cancelled
     * in the same write, so the restored content goes live only when someone who may publish says
     * so; else SaveOptions::$publishAt applies. $loadedVersion is checked as by save(). A restore
     * copies content the record had before, it is not an edit: no save hooks, no checks, no
     * publish (SaveOptions::$publish is refused).
     *
     * @return DynamicRecord|null the restored working copy; null when the record has no such revision
     * @throws \InvalidArgumentException when the type does not publish, or $options asks to publish
     * @throws ValidationException when the storage refuses the revision's content (nothing saved)
     * @throws VersionConflict
     */
    public function restore(string $type, string $id, string $revisionId, SaveOptions $options = new SaveOptions()): ?DynamicRecord
    {
        if (!$this->publishes($type)) {
            throw new \InvalidArgumentException("Type \"{$type}\" does not publish: it has no revisions to restore.");
        }
        if ($options->publish) {
            throw new \InvalidArgumentException('A restore does not publish: publish the restored record with save().');
        }

        return $this->publisher->restore($type, $id, $revisionId, $options->loadedVersion, $this->schedule($options));
    }

    /**
     * Resets the working copy of a publishing type to its published content (Publisher::discard()),
     * under the schedule rule and with $loadedVersion checked, as restore(): no save hooks, no
     * checks, no publish.
     *
     * @return DynamicRecord|null the reset working copy; null when nothing is published
     * @throws \InvalidArgumentException when the type does not publish, or $options asks to publish
     * @throws ValidationException when another record has taken a unique value since (nothing saved)
     * @throws VersionConflict
     */
    public function discard(string $type, string $id, SaveOptions $options = new SaveOptions()): ?DynamicRecord
    {
        if (!$this->publishes($type)) {
            throw new \InvalidArgumentException("Type \"{$type}\" does not publish: it has no published content to go back to.");
        }
        if ($options->publish) {
            throw new \InvalidArgumentException('A discard does not publish: it goes back to what is published.');
        }

        return $this->publisher->discard($type, $id, $options->loadedVersion, $this->schedule($options));
    }

    /** A new position: stored without a new version, and carried into the snapshot of a publishing type. */
    private function storePosition(DynamicRecord $record): void
    {
        $this->store->write($record);
        if ($this->publishes($record->getType()->key)) {
            $this->publisher->syncOrder($record);
        }
    }

    /** @phpstan-assert-if-true !null $this->publisher */
    private function publishes(string $type): bool
    {
        return $this->store->publishes($type);
    }

    /**
     * The schedule rule of a publishing type: the _publish_at a save writes, null to keep the
     * stored one. A save by someone who may not publish cancels a pending schedule; else
     * $publishAt sets it ('' rather than null: SQL drivers skip null values).
     */
    private function schedule(SaveOptions $options): ?string
    {
        if (!$options->mayPublish) {
            return '';
        }

        return $options->publishAt !== null ? $this->publishAt($options->publishAt) : null;
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
