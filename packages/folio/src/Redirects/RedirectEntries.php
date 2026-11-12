<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\ListedRedirectStore;
use Preflow\Routing\Redirect\RedirectPath;
use Preflow\Routing\Redirect\RedirectRejected;
use Preflow\Routing\Redirect\RedirectRules;

/**
 * The redirect entries (Settings → Redirects and the automatic ones), in Folio's own table in the
 * default storage. Paths are stored in RedirectPath's one form; an entry's id comes from its
 * address (area or not, locale, path), so lookups by address go by id, and every other query
 * keeps exact matches only (MySQL compares value columns case-insensitively).
 *
 * Rows are arrays with the table's columns. Nothing here touches the storage before a method
 * is called: a site that has not run `preflow migrate` fails inside the methods only.
 */
final class RedirectEntries implements ListedRedirectStore
{
    public const string TABLE = 'folio_redirects';

    public const array KINDS = ['auto', 'removed', 'manual'];

    private static ?TypeDefinition $definition = null;

    /** @var (\Closure(): RedirectRules)|RedirectRules|null */
    private readonly \Closure|RedirectRules|null $rules;

    /**
     * @param RedirectRules|\Closure(): RedirectRules|null $rules the rules save() holds entries to; a closure
     *        when they are built later (they look through this store); none: save() checks nothing
     */
    public function __construct(private readonly DataManager $dm, RedirectRules|\Closure|null $rules = null)
    {
        $this->rules = $rules;
    }

    public static function definition(): TypeDefinition
    {
        return self::$definition ??= new TypeDefinition(
            key: '_redirects',
            table: self::TABLE,
            storage: 'default',
            fields: [
                'path' => new TypeFieldDefinition('path', 'string'),
                'locale' => new TypeFieldDefinition('locale', 'string'),
                'area' => new TypeFieldDefinition('area', 'boolean'),
                'mode' => new TypeFieldDefinition('mode', 'string'),
                'target_type' => new TypeFieldDefinition('target_type', 'string'),
                'target_id' => new TypeFieldDefinition('target_id', 'string'),
                'target_url' => new TypeFieldDefinition('target_url', 'text'),
                'status' => new TypeFieldDefinition('status', 'number'),
                'kind' => new TypeFieldDefinition('kind', 'string'),
                'source_type' => new TypeFieldDefinition('source_type', 'string'),
                'source_id' => new TypeFieldDefinition('source_id', 'string'),
                'hits' => new TypeFieldDefinition('hits', 'number'),
                'created_at' => new TypeFieldDefinition('created_at', 'string'),
                'created_by' => new TypeFieldDefinition('created_by', 'string'),
                'last_hit_at' => new TypeFieldDefinition('last_hit_at', 'string'),
            ],
            idField: 'id',
        );
    }

    public function exact(string $path, string $locale): ?Redirect
    {
        foreach ($locale === '' ? [''] : [$locale, ''] as $candidate) {
            $row = $this->at($candidate, $path);
            if ($row !== null) {
                return self::redirect($row);
            }
        }

        return null;
    }

    public function areas(string $locale): array
    {
        $areas = [];
        foreach ($this->rows($this->dm->queryIn(self::definition())->where('area', true)) as $row) {
            if ($row['area'] && ($row['locale'] === $locale || $row['locale'] === '')) {
                $areas[] = self::redirect($row);
            }
        }

        return $areas;
    }

    /** @return list<Redirect> */
    public function entries(): array
    {
        return array_map(self::redirect(...), $this->all());
    }

    public function entry(string $id): ?Redirect
    {
        $row = $this->find($id);

        return $row === null ? null : self::redirect($row);
    }

    public function hit(Redirect $redirect, \DateTimeImmutable $at): void
    {
        $row = $this->find($redirect->id);
        if ($row === null) {
            return;
        }
        $row['hits']++;
        $row['last_hit_at'] = $at->format(DATE_ATOM);
        $this->write($row);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $record = $this->dm->findIn(self::definition(), $id);
        if ($record === null || (string) $record->getId() !== $id) {
            return null;
        }

        return self::row($record);
    }

    /** @return list<array<string, mixed>> every entry, by path */
    public function all(): array
    {
        $rows = $this->rows($this->dm->queryIn(self::definition()));
        usort($rows, static fn (array $a, array $b): int => [$a['path'], $a['locale'], $a['area']] <=> [$b['path'], $b['locale'], $b['area']]);

        return $rows;
    }

    /**
     * Stores an entry; the one at the same address (area or not, locale, path) is replaced.
     * Missing columns get their defaults: any language, not an area, mode "one", status 301,
     * kind "manual"; hits, created_at, created_by and last_hit_at are kept from the replaced
     * entry, else no hits, created now.
     *
     * Every write through save() is held to RedirectRules (no chains, a page wins over an exact
     * entry, …), whoever the writer is (a form, a command, an import). That holds when the rules
     * are wired in (the constructor's second argument; Folio's provider does); without them
     * save() checks nothing.
     * $replaces is the stored entry being edited (when its address changes, the caller deletes
     * the old one afterwards): the rules leave it out; by default it is the entry at the same address.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $replaces
     * @throws \InvalidArgumentException for a path that is not a site path, or an unknown mode, status or kind
     * @throws RedirectRejected when the rules refuse the entry
     */
    public function save(array $row, ?array $replaces = null, string $host = ''): void
    {
        $row = $this->complete($row);
        $rules = $this->rules instanceof \Closure ? ($this->rules)() : $this->rules;
        if ($rules !== null) {
            $errors = $rules->check($row, $replaces ?? $this->find($row['id']), $host);
            if (!$errors->isEmpty()) {
                throw new RedirectRejected($errors);
            }
        }
        $this->write($row);
        $rules?->saved($row);
    }

    /**
     * Stores an entry without the rules: Folio's automatic bookkeeping only (RecordAddresses keeps
     * its entries from chaining itself). Same defaults and replacing as save(). Every other
     * writer goes through save().
     *
     * @internal
     * @param array<string, mixed> $row
     * @throws \InvalidArgumentException for a path that is not a site path, or an unknown mode, status or kind
     */
    public function storeUnchecked(array $row): void
    {
        $this->write($this->complete($row));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> the full row, id included
     */
    private function complete(array $row): array
    {
        $path = RedirectPath::normalize((string) ($row['path'] ?? ''))
            ?? throw new \InvalidArgumentException('Not a site path: ' . (string) ($row['path'] ?? ''));
        $area = (bool) ($row['area'] ?? false);
        $locale = (string) ($row['locale'] ?? '');
        $mode = (string) ($row['mode'] ?? AreaMode::One->value);
        $status = (int) ($row['status'] ?? 301);
        $kind = (string) ($row['kind'] ?? 'manual');
        if (AreaMode::tryFrom($mode) === null) {
            throw new \InvalidArgumentException("Unknown redirect mode: {$mode}");
        }
        if ($status !== 301 && $status !== 302) {
            throw new \InvalidArgumentException("Redirect status must be 301 or 302, got {$status}.");
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown redirect kind: {$kind}");
        }

        $id = Redirect::id($area ? 'area' : 'exact', $locale, $path);
        // Re-pointing or editing an entry keeps its counts and who made it when.
        $existing = $this->find($id);
        foreach (['hits', 'created_at', 'created_by', 'last_hit_at'] as $kept) {
            if ($existing !== null && !array_key_exists($kept, $row)) {
                $row[$kept] = $existing[$kept];
            }
        }

        return [
            'id' => $id,
            'path' => $path,
            'locale' => $locale,
            'area' => $area,
            'mode' => $mode,
            'target_type' => self::optional($row['target_type'] ?? null),
            'target_id' => self::optional($row['target_id'] ?? null),
            'target_url' => self::optional($row['target_url'] ?? null),
            'status' => $status,
            'kind' => $kind,
            'source_type' => self::optional($row['source_type'] ?? null),
            'source_id' => self::optional($row['source_id'] ?? null),
            'hits' => (int) ($row['hits'] ?? 0),
            'created_at' => self::optional($row['created_at'] ?? null) ?? (new \DateTimeImmutable())->format(DATE_ATOM),
            'created_by' => self::optional($row['created_by'] ?? null),
            'last_hit_at' => self::optional($row['last_hit_at'] ?? null),
        ];
    }

    public function delete(string $id): void
    {
        if ($this->find($id) !== null) {
            $this->dm->deleteIn(self::definition(), $id);
        }
    }

    /** @return list<array<string, mixed>> the entries pointing at this record */
    public function targeting(string $type, string $id): array
    {
        return $this->matching('target_type', $type, 'target_id', $id);
    }

    /** @return list<array<string, mixed>> the entries made for this record's earlier addresses */
    public function sourcedBy(string $type, string $id): array
    {
        return $this->matching('source_type', $type, 'source_id', $id);
    }

    /** @return array<string, mixed>|null the exact (not area) entry at this address */
    public function at(string $locale, string $path): ?array
    {
        $path = RedirectPath::normalize($path);
        if ($path === null) {
            return null;
        }
        $row = $this->find(Redirect::id('exact', $locale, $path));

        return $row !== null && $row['path'] === $path && $row['locale'] === $locale && !$row['area'] ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    private function matching(string $typeField, string $type, string $idField, string $id): array
    {
        $query = $this->dm->queryIn(self::definition())->where($typeField, $type)->where($idField, $id);

        return array_values(array_filter(
            $this->rows($query),
            static fn (array $row): bool => $row[$typeField] === $type && $row[$idField] === $id,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function rows(\Preflow\Data\QueryBuilder $query): array
    {
        return array_map(self::row(...), $query->get()->items());
    }

    /** @param array<string, mixed> $row */
    private function write(array $row): void
    {
        $this->dm->saveType(DynamicRecord::fromArray(self::definition(), $row), false);
    }

    /** @return array<string, mixed> the row with its columns' PHP types (SQL hands back strings) */
    private static function row(DynamicRecord $record): array
    {
        $value = static fn (string $field): mixed => $record->get($field);

        return [
            'id' => (string) $record->getId(),
            'path' => (string) $value('path'),
            'locale' => (string) $value('locale'),
            'area' => (bool) $value('area'),
            'mode' => (string) ($value('mode') ?? AreaMode::One->value),
            'target_type' => self::optional($value('target_type')),
            'target_id' => self::optional($value('target_id')),
            'target_url' => self::optional($value('target_url')),
            'status' => (int) ($value('status') ?? 301),
            'kind' => (string) $value('kind'),
            'source_type' => self::optional($value('source_type')),
            'source_id' => self::optional($value('source_id')),
            'hits' => (int) $value('hits'),
            'created_at' => self::optional($value('created_at')),
            'created_by' => self::optional($value('created_by')),
            'last_hit_at' => self::optional($value('last_hit_at')),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function redirect(array $row): Redirect
    {
        return new Redirect(
            id: $row['id'],
            path: $row['path'],
            locale: $row['locale'],
            area: $row['area'],
            mode: AreaMode::tryFrom($row['mode']) ?? AreaMode::One,
            targetType: $row['target_type'],
            targetId: $row['target_id'],
            targetUrl: $row['target_url'],
            status: $row['status'] === 302 ? 302 : 301,
        );
    }

    /** '' and null are "none". */
    private static function optional(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
