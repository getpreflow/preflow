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

    /** The longest override text, in characters. */
    public const int MAX_LENGTH = 2000;

    /**
     * An override text as it may be stored and shown, or null when it may not: plain text that
     * is safe anywhere a text is printed unescaped, also inside a double-quoted HTML attribute
     * (the translator's output is marked safe). No `<`, `>`, `"` or backtick, at most
     * MAX_LENGTH characters, not empty; a straight apostrophe becomes the typographic one (’).
     */
    public static function validText(string $text): ?string
    {
        if (trim($text) === '' || mb_strlen($text) > self::MAX_LENGTH || strpbrk($text, '<>"`') !== false) {
            return null;
        }

        return str_replace("'", '’', $text);
    }

    /** Two-letter locale codes only. */
    public static function validLocale(string $locale): bool
    {
        return preg_match('/^[a-z]{2}$/', $locale) === 1;
    }

    /**
     * Why a stored row can't be an override (null: it can): the id must be "{locale}:{key}",
     * the locale two letters, the text validText(). Rows written past set() (an import, a
     * direct insert) are checked with it on import and on every read.
     *
     * @param array<string, mixed> $row
     */
    public static function rowProblem(string $id, array $row): ?string
    {
        $locale = $row['locale'] ?? null;
        $key = $row['key'] ?? null;
        $text = $row['text'] ?? null;
        if (!is_string($locale) || !self::validLocale($locale)) {
            return 'the locale is not a two-letter code';
        }
        if (!is_string($key) || $key === '' || $id !== $locale . ':' . $key || ($row['id'] ?? $id) !== $id) {
            return 'the id is not "{locale}:{key}"';
        }
        if (!is_string($text) || self::validText($text) === null) {
            return 'the text is not plain text (no <, >, " or `, at most ' . self::MAX_LENGTH . ' characters)';
        }

        return null;
    }

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
        if (!self::validLocale($locale)) {
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
     * Every override of a locale, from the table (not the cache), sorted by key. Rows that
     * fail rowProblem() are left out.
     *
     * @return array<string, string>
     */
    public function all(string $locale): array
    {
        if (!self::validLocale($locale)) {
            return [];
        }
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
            $row = ['locale' => $record->get('locale'), 'key' => $record->get('key'), 'text' => $record->get('text')];
            if ($row['locale'] === $locale && self::rowProblem((string) $record->get('id'), $row) === null) {
                $texts[$row['key']] = (string) self::validText($row['text']);
            }
        }
        ksort($texts);

        return $texts;
    }

    /**
     * Stores an override (null or '' removes it; the text goes through validText()) and drops
     * the locale's compiled texts.
     *
     * @throws \InvalidArgumentException for a locale that isn't two letters or a text validText() refuses
     */
    public function set(string $locale, string $key, ?string $text, ?string $by): void
    {
        if (!self::validLocale($locale)) {
            throw new \InvalidArgumentException("Not a two-letter locale: {$locale}");
        }
        if ($text !== null && $text !== '') {
            $text = self::validText($text) ?? throw new \InvalidArgumentException("Not a plain text override for {$key}");
        }
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
        if (!self::validLocale($locale)) {
            throw new \InvalidArgumentException("Not a two-letter locale: {$locale}");
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
