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
 * exported or imported. Today it holds the interface language.
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
            ],
            idField: 'id',
        );
    }

    /** The user's interface language, or null (none chosen; or the table isn't there yet: `preflow migrate` makes it). */
    public function locale(string $authId): ?string
    {
        try {
            $locale = $this->dm->findIn($this->definition(), $authId)?->get('locale');
        } catch (\RuntimeException $e) {
            // No table yet (the driver's "run preflow migrate"): nobody chose, the admin keeps working.
            if ($e instanceof \PDOException || $e->getPrevious() instanceof \PDOException) {
                return null;
            }
            throw $e;
        }

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /** Stores the interface language (null forgets it). The table is created when missing. */
    public function setLocale(string $authId, ?string $locale): void
    {
        $this->ensureTable();
        if ($locale === null || $locale === '') {
            if ($this->dm->findIn($this->definition(), $authId) !== null) {
                $this->dm->deleteIn($this->definition(), $authId);
            }

            return;
        }
        $this->dm->saveType(DynamicRecord::fromArray($this->definition(), ['id' => $authId, 'locale' => $locale]), false);
    }

    private function ensureTable(): void
    {
        if (!$this->tableReady) {
            (new RecordTableSync($this->dm))->sync($this->definition());
            $this->tableReady = true;
        }
    }
}
