<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\AggregateQuery;
use Preflow\Data\AggregatingStorage;
use Preflow\Data\ExclusiveWrites;
use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\SortDirection;
use Preflow\Data\VersionedStorage;

final class JsonFileDriver implements VersionedStorage, ExclusiveWrites, AggregatingStorage
{
    /** @var array<string, true> types whose lock this driver holds (exclusive() nests) */
    private array $locked = [];

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

        if ($type !== null && !self::safeName($type)) {
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
        if (!self::safeName($type) || !self::safeName((string) $id)) {
            return null;
        }
        $path = $this->filePath($type, $id);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        if (!self::safeName($type)) {
            return new ResultSet([]);
        }
        $dir = $this->typeDir($type);

        if (!is_dir($dir)) {
            return new ResultSet([]);
        }

        $items = $this->matching($type, $dir, $query);

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

    /**
     * The type's records that pass the query's search, wheres and OR-groups.
     *
     * @return list<array<string, mixed>>
     */
    private function matching(string $type, string $dir, Query $query): array
    {
        $items = $this->loadRecords($type, $dir);
        if ($query->getSearchTerm() !== null) {
            $items = $this->applySearch($items, $query->getSearchTerm(), $query->getSearchFields(), $query);
        }
        $items = $this->applyWheres($items, $query->getWheres(), $query);

        return $this->applyAnyGroups($items, $query);
    }

    /**
     * Groups and computes in PHP what SQL's GROUP BY does, with the same rules (see
     * AggregateQuery): missing values form one group, measures skip them, number fields count
     * as numbers (numeric text too), other values compare as stored; having, then the orders,
     * then limit and offset.
     */
    public function aggregate(AggregateQuery $aggregate): array
    {
        $type = $aggregate->table();
        $dir = $this->typeDir($type);
        $query = $aggregate->query();
        $numeric = array_fill_keys($aggregate->numericFields(), true);
        $items = self::safeName($type) && is_dir($dir) ? $this->matching($type, $dir, $query) : [];

        $groups = [];
        foreach ($items as $item) {
            $values = [];
            foreach ($aggregate->groups() as $field) {
                $value = $this->aggregateValue($item, $field, $query);
                $values[$field] = isset($numeric[$field]) ? self::numberOrAsStored($value) : $value;
            }
            $key = self::key(array_values($values));
            $groups[$key] ??= ['values' => $values, 'items' => []];
            $groups[$key]['items'][] = $item;
        }
        if ($groups === [] && $aggregate->groups() === []) {
            $groups[] = ['values' => [], 'items' => []]; // totals of nothing are one row
        }

        $rows = [];
        foreach ($groups as $group) {
            $row = $group['values'];
            foreach ($aggregate->measures() as $measure) {
                $row[$measure['as']] = $this->measure($measure, $group['items'], $query, isset($numeric[(string) $measure['field']]));
            }
            if ($this->passesHavings($row, $aggregate->havings())) {
                $rows[] = $row;
            }
        }

        $orders = $aggregate->orders();
        usort($rows, static function (array $a, array $b) use ($orders): int {
            foreach ($orders as $order) {
                $cmp = self::compareNullsFirst($a[$order['name']], $b[$order['name']]);
                if ($cmp !== 0) {
                    return $order['direction'] === SortDirection::Desc ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        $limit = $aggregate->limitValue();
        $offset = $aggregate->offsetValue();
        if ($limit !== null || $offset !== null) {
            $rows = array_slice($rows, $offset ?? 0, $limit);
        }

        return $rows;
    }

    /**
     * One measure over a group's records. count counts records; the others skip missing values
     * (null, and '' where a number is read).
     *
     * @param array{fn: string, field: ?string, as: string} $measure
     * @param list<array<string, mixed>> $items
     */
    private function measure(array $measure, array $items, Query $query, bool $numericField): int|float|string|bool|null
    {
        if ($measure['fn'] === 'count') {
            return count($items);
        }
        $asNumbers = $numericField || in_array($measure['fn'], ['sum', 'avg'], true);
        $values = [];
        foreach ($items as $item) {
            $value = $this->aggregateValue($item, (string) $measure['field'], $query);
            $value = $asNumbers ? self::number($value) : $value;
            if ($value !== null && is_scalar($value)) {
                $values[] = $value;
            }
        }

        return match ($measure['fn']) {
            'countDistinct' => count(array_unique(array_map(static fn (mixed $v): string => self::key([$v]), $values))),
            'sum' => array_sum($values),
            'avg' => $values === [] ? null : array_sum($values) / count($values),
            'min' => $values === [] ? null : array_reduce($values, static fn ($carry, $v) => $carry === null || self::compare($v, $carry) < 0 ? $v : $carry),
            'max' => $values === [] ? null : array_reduce($values, static fn ($carry, $v) => $carry === null || self::compare($v, $carry) > 0 ? $v : $carry),
            default => throw new \InvalidArgumentException(sprintf('Unknown aggregate function "%s".', $measure['fn'])),
        };
    }

    /**
     * A field's value as the SQL drivers hold it: a structured value (a map or list) is the
     * JSON text PdoDriver stores it as, so it groups and compares the same on every storage.
     *
     * @param array<string, mixed> $item
     */
    private function aggregateValue(array $item, string $field, Query $query): mixed
    {
        $value = $this->value($item, $field, $query);

        return $value === null || is_scalar($value)
            ? $value
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * A stored value read as a number, as SQL does: null for null and '' (no number), the
     * leading number of other text (0 if none), booleans as 1 and 0; anything else no value.
     */
    private static function number(mixed $value): int|float|null
    {
        return match (true) {
            $value === null, $value === '' => null,
            is_int($value), is_float($value) => $value,
            is_bool($value) => (int) $value,
            is_string($value) => is_numeric($value) ? $value + 0 : (float) $value,
            default => null,
        };
    }

    /**
     * What tells values apart: their type and value, with 8 and 8.0 one number.
     *
     * @param list<mixed> $values
     */
    private static function key(array $values): string
    {
        $values = array_map(static fn (mixed $v): mixed => is_int($v) ? (float) $v : $v, $values);

        return json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** A number field's group value: its number, but '' and non-scalar values as stored. */
    private static function numberOrAsStored(mixed $value): mixed
    {
        return $value !== '' && is_scalar($value) ? self::number($value) : $value;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{alias: string, operator: string, value: int|float}> $havings
     */
    private function passesHavings(array $row, array $havings): bool
    {
        foreach ($havings as $having) {
            $value = self::number($row[$having['alias']]);
            if ($value === null) {
                return false; // a missing result matches no comparison
            }
            $passes = match ($having['operator']) {
                '=' => $value == $having['value'],
                '!=' => $value != $having['value'],
                '<' => $value < $having['value'],
                '<=' => $value <= $having['value'],
                '>' => $value > $having['value'],
                '>=' => $value >= $having['value'],
                default => false,
            };
            if (!$passes) {
                return false;
            }
        }

        return true;
    }

    /** Ascending order with null first, as SQLite and MySQL sort it. */
    private static function compareNullsFirst(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return ($a !== null) <=> ($b !== null);
        }

        return self::compare($a, $b);
    }

    /** Numbers by value, text by its bytes (as SQL's binary comparison), anything else by PHP's order. */
    private static function compare(mixed $a, mixed $b): int
    {
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        return $a <=> $b;
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        self::assertSafe($type, $id);
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

    /**
     * Checks and writes under the type's lock (exclusive()), so two version-checked writers of
     * the type take turns.
     */
    public function saveIfVersion(string $type, string|int $id, array $data, string $versionField, int $expected, string $idField = 'uuid'): bool
    {
        self::assertSafe($type, $id);
        if (($data[$versionField] ?? null) === null) {
            throw new \InvalidArgumentException("saveIfVersion() needs the version to write in [{$versionField}].");
        }

        return $this->exclusive($type, function () use ($type, $id, $data, $versionField, $expected, $idField): bool {
            $stored = $this->findOne($type, $id, $idField);
            $current = $stored === null ? null : (int) ($stored[$versionField] ?? 0);
            if ($stored === null ? $expected !== 0 : $current !== $expected) {
                return false;
            }
            $this->save($type, $id, $data, $idField);

            return true;
        });
    }

    /**
     * Holding an exclusive lock on the type's lock file (`.lock` in its folder, which no query
     * reads); a nested call for the type runs inside the lock this driver already holds.
     */
    public function exclusive(string $type, \Closure $fn): mixed
    {
        if (!self::safeName($type)) {
            throw new \InvalidArgumentException('Not a usable JSON storage type: ' . json_encode($type, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        if (isset($this->locked[$type])) {
            return $fn();
        }
        $dir = $this->typeDir($type);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}.");
        }
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Could not lock {$dir}/.lock.");
        }
        $this->locked[$type] = true;
        try {
            return $fn();
        } finally {
            unset($this->locked[$type]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        self::assertSafe($type, $id);
        $path = $this->filePath($type, $id);

        if (file_exists($path)) {
            unlink($path);
        }

        $this->bumpGeneration($type);
        $this->clearCache($type);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return self::safeName($type) && self::safeName((string) $id) && file_exists($this->filePath($type, $id));
    }

    public function lastInsertId(): string|int
    {
        return '';
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        throw new \RuntimeException('Raw SQL queries are not supported by the JSON file driver.');
    }

    /**
     * A type or id that can be one segment of a file path: not "." or "..",
     * and without "/", "\\" or NUL. Anything else would leave the type's folder.
     */
    private static function safeName(string $name): bool
    {
        return $name !== '.' && $name !== '..' && strpbrk($name, "/\\\0") === false;
    }

    private static function assertSafe(string $type, string|int $id): void
    {
        if (!self::safeName($type)) {
            throw new \InvalidArgumentException('Not a usable JSON storage type: ' . json_encode($type, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        if (!self::safeName((string) $id)) {
            throw new \InvalidArgumentException('Not a usable JSON record id: ' . json_encode((string) $id, JSON_INVALID_UTF8_SUBSTITUTE));
        }
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
