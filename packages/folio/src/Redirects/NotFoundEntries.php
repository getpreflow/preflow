<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Routing\Redirect\NotFoundLog;
use Preflow\Routing\Redirect\RedirectPath;

/**
 * The not-found list: public addresses that ended in a 404, per language, with how often and
 * when they were hit; nothing about the visitor. At most $limit rows (the least recently hit go
 * first); single hits are kept but not shown; a dismissed address comes back only when it is
 * hit again 30 days or more after the dismissal. Paths are kept in RedirectPath's one form.
 *
 * Nothing here touches the storage before a method is called (see RedirectEntries).
 */
final class NotFoundEntries implements NotFoundLog
{
    public const string TABLE = 'folio_not_found';

    /** A dismissed address is counted afresh when hit this long after the dismissal. */
    private const string DISMISSED_FOR = '+30 days';

    private static ?TypeDefinition $definition = null;

    public function __construct(
        private readonly DataManager $dm,
        private readonly int $limit = 1000,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException("The not-found limit must be 1 or more, got {$limit}.");
        }
    }

    public static function definition(): TypeDefinition
    {
        return self::$definition ??= new TypeDefinition(
            key: '_not_found',
            table: self::TABLE,
            storage: 'default',
            fields: [
                'path' => new TypeFieldDefinition('path', 'string'),
                'locale' => new TypeFieldDefinition('locale', 'string'),
                'hits' => new TypeFieldDefinition('hits', 'number'),
                'first_hit_at' => new TypeFieldDefinition('first_hit_at', 'string'),
                'last_hit_at' => new TypeFieldDefinition('last_hit_at', 'string'),
                'dismissed_at' => new TypeFieldDefinition('dismissed_at', 'string'),
            ],
            idField: 'id',
        );
    }

    /** The row id of an address. */
    public static function id(string $locale, string $path): string
    {
        return substr(hash('sha256', implode("\n", [$locale, $path])), 0, 32);
    }

    public function record(string $path, string $locale, \DateTimeImmutable $at): void
    {
        $path = RedirectPath::normalize($path);
        if ($path === null) {
            return;
        }
        $stamp = $at->format(DATE_ATOM);
        $row = $this->at($locale, $path);

        if ($row === null) {
            $this->write([
                'id' => self::id($locale, $path),
                'path' => $path,
                'locale' => $locale,
                'hits' => 1,
                'first_hit_at' => $stamp,
                'last_hit_at' => $stamp,
                'dismissed_at' => null,
            ]);
            $this->trim();

            return;
        }

        $dismissed = $row['dismissed_at'] !== null ? self::time($row['dismissed_at']) : null;
        if ($dismissed !== null && $at >= $dismissed->modify(self::DISMISSED_FOR)) {
            $row['hits'] = 1;
            $row['first_hit_at'] = $stamp;
            $row['dismissed_at'] = null;
        } elseif ($dismissed === null) {
            $row['hits']++;
        }
        $row['last_hit_at'] = $stamp;
        $this->write($row);
    }

    /** @return list<array<string, mixed>> hit twice or more and not dismissed: most hits first, then the most recent */
    public function visible(): array
    {
        $rows = array_values(array_filter(
            $this->rows(),
            static fn (array $row): bool => $row['hits'] >= 2 && $row['dismissed_at'] === null,
        ));
        usort($rows, static fn (array $a, array $b): int => [$b['hits'], self::timestamp($b['last_hit_at'])]
            <=> [$a['hits'], self::timestamp($a['last_hit_at'])]);

        return $rows;
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

    public function dismiss(string $id, \DateTimeImmutable $at): void
    {
        $row = $this->find($id);
        if ($row !== null) {
            $row['dismissed_at'] = $at->format(DATE_ATOM);
            $this->write($row);
        }
    }

    /** Drops an address from the list (it got a redirect, or a page took it). */
    public function forget(string $locale, string $path): void
    {
        $path = RedirectPath::normalize($path);
        if ($path !== null && $this->at($locale, $path) !== null) {
            $this->dm->deleteIn(self::definition(), self::id($locale, $path));
        }
    }

    /** @return array<string, mixed>|null */
    private function at(string $locale, string $path): ?array
    {
        $row = $this->find(self::id($locale, $path));

        return $row !== null && $row['locale'] === $locale && $row['path'] === $path ? $row : null;
    }

    /** Deletes the least recently hit rows beyond the limit. */
    private function trim(): void
    {
        $rows = $this->rows();
        $over = count($rows) - $this->limit;
        if ($over <= 0) {
            return;
        }
        usort($rows, static fn (array $a, array $b): int => self::timestamp($a['last_hit_at']) <=> self::timestamp($b['last_hit_at']));
        foreach (array_slice($rows, 0, $over) as $row) {
            $this->dm->deleteIn(self::definition(), $row['id']);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return array_map(self::row(...), $this->dm->queryIn(self::definition())->get()->items());
    }

    /** @param array<string, mixed> $row */
    private function write(array $row): void
    {
        $this->dm->saveType(DynamicRecord::fromArray(self::definition(), $row), false);
    }

    /** @return array<string, mixed> */
    private static function row(DynamicRecord $record): array
    {
        $dismissed = $record->get('dismissed_at');

        return [
            'id' => (string) $record->getId(),
            'path' => (string) $record->get('path'),
            'locale' => (string) $record->get('locale'),
            'hits' => (int) $record->get('hits'),
            'first_hit_at' => (string) $record->get('first_hit_at'),
            'last_hit_at' => (string) $record->get('last_hit_at'),
            'dismissed_at' => $dismissed === null || $dismissed === '' ? null : (string) $dismissed,
        ];
    }

    private static function time(string $stamp): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($stamp);
        } catch (\Exception) {
            return null;
        }
    }

    private static function timestamp(string $stamp): int
    {
        return self::time($stamp)?->getTimestamp() ?? 0;
    }
}
