<?php

declare(strict_types=1);

namespace Preflow\Folio\Permissions;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * The declared permissions this site has already seen (folio_known_permissions), one row per
 * name. A permission declared since (a new package, extension or Folio release) is "fresh":
 * the Roles screen announces it and `preflow migrate` lists it, until an admin dismisses it or
 * adds it to a role from the notice. Folio's own table, not a content type.
 *
 * @since 0.53
 */
final class KnownPermissions
{
    public const string TABLE = 'folio_known_permissions';

    /**
     * Announced even on the first run, when every other declared permission becomes known
     * silently: permissions that arrived in the release which introduced this list.
     */
    public const array ANNOUNCE_ON_FIRST_RUN = ['redirects.manage'];

    private static ?TypeDefinition $definition = null;

    public function __construct(
        private readonly DataManager $dm,
        private readonly PermissionRegistry $registry,
    ) {}

    public static function definition(): TypeDefinition
    {
        return self::$definition ??= new TypeDefinition(
            key: '_known_permissions',
            table: self::TABLE,
            storage: 'default',
            fields: [
                'known_at' => new TypeFieldDefinition('known_at', 'string'),
            ],
            idField: 'name',
        );
    }

    /**
     * The enabled declared permissions not known yet, sorted. Disabled ones (a switched-off
     * extension) are never fresh; they become fresh once enabled. On an empty list, first
     * records every enabled permission except ANNOUNCE_ON_FIRST_RUN as known (the baseline),
     * so an existing site is not flooded with everything it already has.
     *
     * Throws where the table does not exist yet (a site not migrated); nothing is written then.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        $enabled = $this->enabled();
        $known = $this->known();
        if ($known === []) {
            $baseline = array_values(array_diff($enabled, self::ANNOUNCE_ON_FIRST_RUN));
            $this->baseline($baseline);
            $known = $baseline;
        }
        $fresh = array_values(array_diff($enabled, $known));
        sort($fresh);

        return $fresh;
    }

    /** @param list<string> $names known for good: never fresh again */
    public function markKnown(array $names): void
    {
        $at = self::now();
        foreach (array_unique($names) as $name) {
            $this->save($name, $at);
        }
    }

    /**
     * For `preflow migrate` (the folio.permissions step): one line naming the fresh
     * permissions with their labels, or nothing.
     *
     * @return list<string>
     */
    public function migrateLines(): array
    {
        $fresh = $this->fresh();
        if ($fresh === []) {
            return [];
        }
        $all = $this->registry->all();
        $named = array_map(static function (string $name) use ($all): string {
            $label = $all[$name]['label'] ?? '';

            return $label === '' ? $name : "{$name} ({$label})";
        }, $fresh);

        return ['New permissions to review in Settings → Roles: ' . implode(', ', $named)];
    }

    /** @return list<string> */
    private function enabled(): array
    {
        $names = [];
        foreach (array_keys($this->registry->all()) as $name) {
            if ($this->registry->disabledReason((string) $name) === null) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function known(): array
    {
        return array_map(
            static fn (DynamicRecord $row): string => (string) $row->getId(),
            $this->dm->queryIn(self::definition())->get()->items(),
        );
    }

    /**
     * Writes the whole baseline in one pass, in one transaction on SQL storage: a failure
     * part-way leaves the list empty, so the next call starts the baseline over instead of
     * announcing what the missing rows would have covered.
     *
     * @param list<string> $names
     */
    private function baseline(array $names): void
    {
        $driver = $this->dm->driver(self::definition()->storage);
        $pdo = $driver instanceof PdoDriver && !$driver->pdo()->inTransaction() ? $driver->pdo() : null;
        $pdo?->beginTransaction();
        try {
            $this->markKnown($names);
            $pdo?->commit();
        } catch (\Throwable $e) {
            if ($pdo?->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function save(string $name, string $at): void
    {
        $this->dm->saveType(DynamicRecord::fromArray(self::definition(), [
            'name' => $name,
            'known_at' => $at,
        ]), false);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
