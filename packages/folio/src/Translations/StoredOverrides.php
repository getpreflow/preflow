<?php

declare(strict_types=1);

namespace Preflow\Folio\Translations;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\I18n\TranslationOverrides;

/**
 * The interface texts admins changed (Settings → Translations), one record per locale and key
 * (id "{locale}:{key}") in Folio's own table in the default storage: not a content type, but
 * exported and imported with the content. The translator reads them through the compiled
 * per-locale cache (OverridesCache); every change removes the locale's compiled file.
 */
final class StoredOverrides implements TranslationOverrides
{
    public const string TABLE = 'folio_translation_overrides';

    private ?TypeDefinition $definition = null;

    private bool $tableReady = false;

    public function __construct(
        private readonly DataManager $dm,
        private readonly OverridesCache $cache,
    ) {}

    public function definition(): TypeDefinition
    {
        return $this->definition ??= new TypeDefinition(
            key: '_translation_overrides',
            table: self::TABLE,
            storage: 'default',
            fields: [
                'locale' => new TypeFieldDefinition('locale', 'string'),
                'key' => new TypeFieldDefinition('key', 'string'),
                'text' => new TypeFieldDefinition('text', 'text'),
                'updated_by' => new TypeFieldDefinition('updated_by', 'string'),
                'updated_at' => new TypeFieldDefinition('updated_at', 'string'),
            ],
            idField: 'id',
        );
    }

    public function get(string $locale, string $key): ?string
    {
        if (preg_match('/^[a-z]{2}$/', $locale) !== 1) {
            return null;
        }
        $texts = $this->cache->read($locale);
        if ($texts === null) {
            $texts = $this->all($locale);
            $this->cache->write($locale, $texts);
        }

        return $texts[$key] ?? null;
    }

    /**
     * Every override of a locale, from the table (not the cache), sorted by key.
     *
     * @return array<string, string>
     */
    public function all(string $locale): array
    {
        try {
            $records = $this->dm->queryIn($this->definition())->where('locale', $locale)->get()->items();
        } catch (\RuntimeException $e) {
            // No table yet (`preflow migrate` or the first save makes it): nothing was changed.
            if ($e instanceof \PDOException || $e->getPrevious() instanceof \PDOException) {
                return [];
            }
            throw $e;
        }
        $texts = [];
        foreach ($records as $record) {
            $key = $record->get('key');
            $text = $record->get('text');
            if (is_string($key) && is_string($text) && $text !== '' && $record->get('locale') === $locale) {
                $texts[$key] = $text;
            }
        }
        ksort($texts);

        return $texts;
    }

    /** Stores an override (null or '' removes it) and drops the locale's compiled texts. */
    public function set(string $locale, string $key, ?string $text, ?string $by): void
    {
        $this->ensureTable();
        $id = $locale . ':' . $key;
        if ($text === null || $text === '') {
            if ($this->dm->findIn($this->definition(), $id) !== null) {
                $this->dm->deleteIn($this->definition(), $id);
            }
        } else {
            $this->dm->saveType(DynamicRecord::fromArray($this->definition(), [
                'id' => $id,
                'locale' => $locale,
                'key' => $key,
                'text' => $text,
                'updated_by' => $by ?? '',
                'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]), false);
        }
        $this->forget($locale);
    }

    /** Drops the compiled texts of a locale, or of every locale (after an import). */
    public function forget(?string $locale = null): void
    {
        if ($locale === null) {
            $this->cache->clear();

            return;
        }
        $this->cache->forget($locale);
    }

    /** Creates the table when it is missing (SQL storage). */
    public function ensureTable(): void
    {
        if (!$this->tableReady) {
            (new RecordTableSync($this->dm))->sync($this->definition());
            $this->tableReady = true;
        }
    }
}
