<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\SortDirection;
use Preflow\Data\StorageDriver;

final class JsonFileDriver implements StorageDriver
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?string $cacheDir = null,
    ) {}

    public function cacheDir(): ?string
    {
        return $this->cacheDir;
    }

    /** Drop the query cache of one type, or of every type. */
    public function clearCache(?string $type = null): void
    {
        if ($this->cacheDir === null) {
            return;
        }

        $files = $type !== null ? [$this->cacheFile($type)] : (glob($this->cacheDir . '/json-*.json') ?: []);
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    private function cacheFile(string $type): string
    {
        return $this->cacheDir . '/json-' . $type . '.json';
    }

    /** The type's generation file: rewritten by every save / delete, kept by clearCache(). */
    private function generationFile(string $type): string
    {
        return $this->cacheDir . '/json-' . $type . '.gen';
    }

    /** The type's current generation ('0' before its first save or delete through a driver). */
    private function generation(string $type): string
    {
        $raw = @file_get_contents($this->generationFile($type));

        return $raw === false || $raw === '' ? '0' : $raw;
    }

    /**
     * Move the type's generation on (a fresh random value, written atomically): a cache built
     * from records read before this write carries the old generation and is never used again,
     * however its write races with this one.
     */
    private function bumpGeneration(string $type): void
    {
        if ($this->cacheDir === null) {
            return;
        }
        try {
            if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                return;
            }
            $file = $this->generationFile($type);
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, bin2hex(random_bytes(8)), LOCK_EX) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
            }
        } catch (\Throwable) {
            // Without a generation file the signature and clearCache() still apply.
        }
    }

    /**
     * The type's generation, record count, newest mtime and total size of the record files,
     * plus the directory's mtime: moves on every save / delete through a driver and when files
     * are added, removed or rewritten outside it.
     *
     * @param list<string> $files
     * @return array{string, int, int, int, int}
     */
    private function signature(string $generation, string $dir, array $files): array
    {
        clearstatcache();
        $newest = 0;
        $size = 0;
        foreach ($files as $file) {
            $newest = max($newest, (int) @filemtime($file));
            $size += (int) @filesize($file);
        }

        return [$generation, count($files), $newest, $size, (int) @filemtime($dir)];
    }

    /** @return list<array<string, mixed>> every record of the type, with `_id` */
    private function loadRecords(string $type, string $dir): array
    {
        if ($this->cacheDir === null) {
            return $this->readRecords(glob($dir . '/*.json') ?: []);
        }

        // The generation is read before the records: a save after this point moves it on.
        $generation = $this->generation($type);
        $files = glob($dir . '/*.json') ?: [];
        $signature = $this->signature($generation, $dir, $files);
        $cacheFile = $this->cacheFile($type);

        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $cached = json_decode($raw, true);
            if (is_array($cached) && ($cached['signature'] ?? null) === $signature && is_array($cached['records'] ?? null)) {
                return $cached['records'];
            }
        }

        $records = $this->readRecords($files);
        // Changed while reading: use what was read, but do not cache it.
        if ($this->signature($this->generation($type), $dir, glob($dir . '/*.json') ?: []) === $signature) {
            $this->writeCache($cacheFile, ['signature' => $signature, 'records' => $records]);
        }

        return $records;
    }

    /**
     * @param list<string> $files
     * @return list<array<string, mixed>>
     */
    private function readRecords(array $files): array
    {
        $items = [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $data['_id'] = basename($file, '.json');
            $items[] = $data;
        }

        return $items;
    }

    /** @param array<string, mixed> $payload */
    private function writeCache(string $cacheFile, array $payload): void
    {
        try {
            if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                return;
            }
            $tmp = $cacheFile . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false || !@rename($tmp, $cacheFile)) {
                @unlink($tmp);
            }
        } catch (\Throwable) {
            // A cache that cannot be written only costs speed.
        }
    }

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        $path = $this->filePath($type, $id);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        $dir = $this->typeDir($type);

        if (!is_dir($dir)) {
            return new ResultSet([]);
        }

        $items = $this->loadRecords($type, $dir);

        // Apply search
        if ($query->getSearchTerm() !== null) {
            $items = $this->applySearch($items, $query->getSearchTerm(), $query->getSearchFields(), $query);
        }

        // Apply where conditions
        $items = $this->applyWheres($items, $query->getWheres(), $query);
        $items = $this->applyAnyGroups($items, $query);

        // Apply ordering
        $items = $this->applyOrderBy($items, $query->getOrderBy(), $query);

        $total = count($items);

        // Apply limit/offset
        if ($query->getOffset() !== null || $query->getLimit() !== null) {
            $items = array_slice($items, $query->getOffset() ?? 0, $query->getLimit());
        }

        // Remove internal _id
        $items = array_map(function (array $item) {
            unset($item['_id']);
            return $item;
        }, $items);

        return new ResultSet(array_values($items), $total);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $dir = $this->typeDir($type);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $this->filePath($type, $id);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Write a temp file next to it, then rename: readers (and exports) only
        // ever see the old or the new record, never half of one.
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not write {$path}.");
        }

        $this->bumpGeneration($type);
        $this->clearCache($type);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $path = $this->filePath($type, $id);

        if (file_exists($path)) {
            unlink($path);
        }

        $this->bumpGeneration($type);
        $this->clearCache($type);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return file_exists($this->filePath($type, $id));
    }

    public function lastInsertId(): string|int
    {
        return '';
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        throw new \RuntimeException('Raw SQL queries are not supported by the JSON file driver.');
    }

    private function typeDir(string $type): string
    {
        return $this->basePath . '/' . $type;
    }

    private function filePath(string $type, string|int $id): string
    {
        return $this->typeDir($type) . '/' . $id . '.json';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array{field: string, operator: string, value: mixed, boolean: string}> $wheres
     * @return array<int, array<string, mixed>>
     */
    private function applyWheres(array $items, array $wheres, ?Query $query = null): array
    {
        if ($wheres === []) {
            return $items;
        }

        return array_values(array_filter($items, function (array $item) use ($wheres, $query) {
            $result = true;

            foreach ($wheres as $i => $where) {
                $match = $this->evaluateCondition($item, $where, $query);

                if ($i === 0) {
                    $result = $match;
                } elseif ($where['boolean'] === 'OR') {
                    $result = $result || $match;
                } else {
                    $result = $result && $match;
                }
            }

            return $result;
        }));
    }

    /**
     * @param array<string, mixed> $item
     * @param array{field: string, operator: string, value: mixed, boolean: string} $where
     */
    /** A field's value, in the query's locale for localized (translatable) fields. */
    private function value(array $item, string $field, ?Query $query): mixed
    {
        $raw = $item[$field] ?? null;
        $localization = $query?->localizationFor($field);
        if ($localization === null) {
            return $raw;
        }

        return LocaleValue::pick(
            $raw,
            Locales::fromPair($localization['locale'], $localization['default']),
            $localization['locale'],
        );
    }

    private function evaluateCondition(array $item, array $where, ?Query $query = null): bool
    {
        $fieldValue = $this->value($item, $where['field'], $query);
        $condValue = $where['value'];

        return match ($where['operator']) {
            '=' => $fieldValue === $condValue,
            '!=' => $fieldValue !== $condValue,
            '>' => $fieldValue > $condValue,
            '>=' => $fieldValue >= $condValue,
            '<' => $fieldValue < $condValue,
            '<=' => $fieldValue <= $condValue,
            'LIKE' => $this->matchLike((string)($fieldValue ?? ''), (string)$condValue),
            default => false,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function applyAnyGroups(array $items, Query $query): array
    {
        foreach ($query->getAnyGroups() as $group) {
            $items = array_values(array_filter($items, function (array $item) use ($group, $query) {
                foreach ($group as $condition) {
                    if ($this->matchesAny($this->value($item, $condition['field'], $query), $condition)) {
                        return true;
                    }
                }
                return false;
            }));
        }

        return $items;
    }

    /** @param array{field: string, operator: string, value: mixed} $condition */
    private function matchesAny(mixed $fieldValue, array $condition): bool
    {
        $value = $condition['value'];

        return match ($condition['operator']) {
            'CONTAINS' => is_scalar($fieldValue) && mb_stripos((string) $fieldValue, (string) $value) !== false,
            'IN' => is_scalar($fieldValue) && in_array((string) $fieldValue, array_map('strval', array_filter((array) $value, 'is_scalar')), true),
            'HAS' => $this->holdsAny($fieldValue, array_map('strval', (array) $value)),
            default => $fieldValue === $value,
        };
    }

    /** @param list<string> $ids */
    private function holdsAny(mixed $stored, array $ids): bool
    {
        if ($ids === [] || $stored === null || $stored === '') {
            return false;
        }
        if (is_array($stored)) {
            $held = array_map('strval', array_filter($stored, 'is_scalar'));
        } else {
            $held = [(string) $stored];
            $decoded = is_string($stored) ? json_decode($stored, true) : null;
            if (is_array($decoded)) {
                $held = array_map('strval', array_filter($decoded, 'is_scalar'));
            }
        }

        return array_intersect($ids, $held) !== [];
    }

    private function matchLike(string $value, string $pattern): bool
    {
        $parts = preg_split('/(%|_)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '/^';
        foreach ($parts as $part) {
            if ($part === '%') {
                $regex .= '.*';
            } elseif ($part === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($part, '/');
            }
        }
        $regex .= '$/i';

        return (bool) preg_match($regex, $value);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function applySearch(array $items, string $term, array $fields, ?Query $query = null): array
    {
        $term = mb_strtolower($term);

        return array_values(array_filter($items, function (array $item) use ($term, $fields, $query) {
            $searchIn = $fields ?: array_keys($item);

            foreach ($searchIn as $field) {
                $value = $this->value($item, $field, $query);
                if (is_string($value)) {
                    if (str_contains(mb_strtolower($value), $term)) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array{field: string, direction: SortDirection}> $orderBy
     * @return array<int, array<string, mixed>>
     */
    private function applyOrderBy(array $items, array $orderBy, ?Query $query = null): array
    {
        if ($orderBy === []) {
            return $items;
        }

        usort($items, function (array $a, array $b) use ($orderBy, $query) {
            foreach ($orderBy as $order) {
                $field = $order['field'];
                $aVal = $this->value($a, $field, $query) ?? '';
                $bVal = $this->value($b, $field, $query) ?? '';
                $cmp = is_numeric($aVal) && is_numeric($bVal)
                    ? $aVal <=> $bVal
                    : strcmp((string)$aVal, (string)$bVal);

                if ($cmp !== 0) {
                    return $order['direction'] === SortDirection::Desc ? -$cmp : $cmp;
                }
            }
            return 0;
        });

        return $items;
    }
}
