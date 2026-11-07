<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;

/**
 * Each Folio user's own settings, one record per auth id (a record user's id, a config user's
 * address) in the default storage: so config users need no record, and a choice survives
 * signing out. Folio's own table, not a content type: never in the TypeCatalog, never
 * exported or imported. It holds the interface language and which sidebar sections are open.
 */
final class UserPreferences
{
    public const string TABLE = 'folio_user_preferences';

    private ?TypeDefinition $definition = null;

    private bool $tableReady = false;

    public function __construct(
        private readonly DataManager $dm,
    ) {}

    public function definition(): TypeDefinition
    {
        return $this->definition ??= new TypeDefinition(
            key: '_user_preferences',
            table: self::TABLE,
            storage: 'default',
            fields: [
                'locale' => new \Preflow\Data\TypeFieldDefinition('locale', 'string'),
                'nav' => new \Preflow\Data\TypeFieldDefinition('nav', 'json'),
            ],
            idField: 'id',
        );
    }

    /** The user's interface language, or null (none chosen; or the table isn't there yet: `preflow migrate` makes it). */
    public function locale(string $authId): ?string
    {
        $locale = $this->read($authId)?->get('locale');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * Which sidebar sections the user left open (true) or closed (false), by section name. Empty
     * when nothing is saved, or the table or its column isn't there yet.
     *
     * @return array<string, bool>
     */
    public function navSections(string $authId): array
    {
        return self::sections($this->read($authId)?->get('nav'));
    }

    /** Stores the language (null forgets only the language; the sections stay). The table is created when missing. */
    public function setLocale(string $authId, ?string $locale): void
    {
        $this->store($authId, ($locale === null || $locale === '') ? null : $locale, null);
    }

    /** Remembers one sidebar section as open or closed; the language and the other sections stay. */
    public function setNavSection(string $authId, string $section, bool $open): void
    {
        $this->store($authId, false, [$section => $open]);
    }

    /**
     * Writes the changed parts of the record. $locale: false keeps it, null clears it. $nav: the
     * keys to set, or null to keep the map. The row goes when neither language nor map is left.
     *
     * @param array<string, bool>|null $nav
     */
    private function store(string $authId, string|false|null $locale, ?array $nav): void
    {
        $this->ensureTable();
        $current = $this->read($authId);
        $keptLocale = $locale === false ? ($current?->get('locale')) : $locale;
        $keptLocale = is_string($keptLocale) && $keptLocale !== '' ? $keptLocale : null;
        $map = self::sections($current?->get('nav'));
        if ($nav !== null) {
            $map = array_replace($map, $nav);
        }

        if ($keptLocale === null && $map === []) {
            if ($current !== null) {
                $this->dm->deleteIn($this->definition(), $authId);
            }

            return;
        }
        $this->dm->saveType(DynamicRecord::fromArray($this->definition(), [
            'id' => $authId,
            'locale' => $keptLocale,
            'nav' => $map === [] ? null : $map,
        ]), false);
    }

    private function read(string $authId): ?DynamicRecord
    {
        try {
            $record = $this->dm->findIn($this->definition(), $authId);
        } catch (\RuntimeException $e) {
            // No table or column yet (the driver's "run preflow migrate"): nothing saved, the admin keeps working.
            if ($e instanceof \PDOException || $e->getPrevious() instanceof \PDOException) {
                return null;
            }
            throw $e;
        }

        return $record instanceof DynamicRecord ? $record : null;
    }

    /** @return array<string, bool> only the bool entries of a stored map */
    private static function sections(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_filter($raw, static fn (mixed $v): bool => is_bool($v));
    }

    private function ensureTable(): void
    {
        if (!$this->tableReady) {
            (new RecordTableSync($this->dm))->sync($this->definition());
            $this->tableReady = true;
        }
    }
}
