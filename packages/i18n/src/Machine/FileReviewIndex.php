<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * The review index as one JSON file. The worker and web requests both write it, so every change reads
 * and rewrites the file under an exclusive lock on a sibling .lock file; the write goes to a temp file
 * that is renamed into place, so a reader never sees half a file.
 */
final class FileReviewIndex implements ReviewIndex
{
    private readonly \Closure $now;

    /** @param ?\Closure(): \DateTimeInterface $now */
    public function __construct(private readonly string $file, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): \DateTimeInterface => new \DateTimeImmutable();
    }

    public function put(string $collection, string $id, string $title, array $counts): void
    {
        $counts = array_filter($counts, static fn (int $n): bool => $n > 0);
        if ($counts === []) {
            $this->forget($collection, $id);

            return;
        }
        $entry = new ReviewEntry($collection, $id, $title, $counts, ($this->now)()->format(\DATE_ATOM));

        $this->modify(static function (array $stored) use ($entry): array {
            $stored[self::key($entry->collection, $entry->id)] = $entry;

            return $stored;
        });
    }

    public function forget(string $collection, string $id): void
    {
        $this->modify(static function (array $stored) use ($collection, $id): array {
            unset($stored[self::key($collection, $id)]);

            return $stored;
        });
    }

    public function entries(): array
    {
        $entries = array_values($this->read());
        usort($entries, static fn (ReviewEntry $a, ReviewEntry $b): int => strcmp($b->updatedAt, $a->updatedAt));

        return $entries;
    }

    public function replaceAll(array $entries): void
    {
        $this->modify(static function () use ($entries): array {
            $stored = [];
            foreach ($entries as $entry) {
                $stored[self::key($entry->collection, $entry->id)] = $entry;
            }

            return $stored;
        });
    }

    /** @param \Closure(array<string, ReviewEntry>): array<string, ReviewEntry> $change */
    private function modify(\Closure $change): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create review index directory {$dir}.");
        }
        $lock = fopen($this->file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Cannot lock review index {$this->file}.");
        }
        try {
            $rows = [];
            foreach ($change($this->read()) as $entry) {
                $rows[] = [
                    'collection' => $entry->collection,
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'counts' => $entry->counts,
                    'updatedAt' => $entry->updatedAt,
                ];
            }
            $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $this->file)) {
                @unlink($tmp);
                throw new \RuntimeException("Cannot write review index {$this->file}.");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, ReviewEntry> a missing or unreadable file counts as empty */
    private function read(): array
    {
        $json = @file_get_contents($this->file);
        $rows = $json === false ? null : json_decode($json, true);
        if (!is_array($rows)) {
            return [];
        }
        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['collection'] ?? null) || !is_string($row['id'] ?? null)
                || !is_string($row['title'] ?? null) || !is_array($row['counts'] ?? null) || !is_string($row['updatedAt'] ?? null)) {
                continue;
            }
            $counts = array_map('intval', $row['counts']);
            $entries[self::key($row['collection'], $row['id'])] = new ReviewEntry($row['collection'], $row['id'], $row['title'], $counts, $row['updatedAt']);
        }

        return $entries;
    }

    private static function key(string $collection, string $id): string
    {
        return $collection . "\0" . $id;
    }
}
