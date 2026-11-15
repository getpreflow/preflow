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
use Preflow\Data\Text\TextMatch;
use Preflow\Data\VersionedStorage;

final class JsonFileDriver implements VersionedStorage, ExclusiveWrites, AggregatingStorage, \Preflow\Data\BulkDeletes, \Preflow\Data\ConditionalUpdates, \Preflow\Data\ChangeTokens, \Preflow\Data\DescribesColumns, \Preflow\Data\AtomicCounters
{
    /** How a record file is encoded (0.78): the same bytes for the same record, whoever writes it. */
    private const int RECORD_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** @var array<string, true> types whose lock this driver holds (exclusive() nests) */
    private array $locked = [];

    /**
     * Names this data path's files in the cache directory: drivers of different data paths
     * (each test's own data, parallel workers) can share one cache directory without ever
     * reading each other's records or moving each other's generations.
     */
    private readonly string $pathKey;

    public function __construct(
        private readonly string $basePath,
        private readonly ?string $cacheDir = null,
    ) {
        $this->pathKey = substr(hash('xxh128', self::resolvedPath($basePath)), 0, 16);
    }

    /** The key naming this data path's cache files: json-{type}-{pathKey}.json / .gen. */
    public function pathKey(): string
    {
        return $this->pathKey;
    }

    /**
     * The path with its nearest existing ancestor resolved (symlinks, `..`): the same before
     * and after the data directory is created.
     */
    private static function resolvedPath(string $path): string
    {
        $rest = '';
        $current = $path === '/' ? '/' : rtrim($path, '/');
        while (($real = realpath($current === '' ? '.' : $current)) === false) {
            $parent = dirname($current);
            if ($parent === $current) {
                return $path;
            }
            $rest = '/' . basename($current) . $rest;
            $current = $parent;
        }

        return $rest === '' ? $real : rtrim($real, '/') . $rest;
    }

    public function cacheDir(): ?string
    {
        return $this->cacheDir;
    }

    /**
     * Drop this data path's query cache of one type, or of every type. Other data paths'
     * caches in the same directory stay (`cache:clear` removes them all).
     */
    public function clearCache(?string $type = null): void
    {
        if ($this->cacheDir === null) {
            return;
        }

        if ($type !== null && !self::safeName($type)) {
            return;
        }
        $files = $type !== null ? [$this->cacheFile($type)] : (glob($this->cacheDir . '/json-*-' . $this->pathKey . '.json') ?: []);
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    private function cacheFile(string $type): string
    {
        return $this->cacheDir . '/json-' . $type . '-' . $this->pathKey . '.json';
    }

    /** The type's generation file: rewritten by every save / delete, kept by clearCache(). */
    private function generationFile(string $type): string
    {
        return $this->cacheDir . '/json-' . $type . '-' . $this->pathKey . '.gen';
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
     * The type's change token (ChangeTokens): its generation, which every save and delete through
     * a driver moves, with the directory's mtime (records added or removed outside one), as the
     * query cache's cheap check reads them. Null without a cache directory (no generation is kept).
     *
     * @since 0.77
     */
    public function changeToken(string $type): ?string
    {
        if ($this->cacheDir === null || !self::safeName($type)) {
            return null;
        }

        return implode('|', $this->quickSignature($this->generation($type), $this->typeDir($type)));
    }

    /**
     * The type's generation, record count, newest mtime and total size of the record files,
     * plus the directory's mtime and the data path's key (the same for every spelling of the
     * path): moves on every save / delete through a driver and when files are added, removed or
     * rewritten outside it.
     *
     * @param list<string> $files
     * @return array{string, int, int, int, int, string}
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

        return [$generation, count($files), $newest, $size, (int) @filemtime($dir), $this->pathKey];
    }

    /**
     * The cheap check a query makes: the type's generation, its directory's mtime and the data
     * path's key. Adding or removing a record changes the directory's mtime; a save or delete
     * through any driver moves the generation. Rewriting a record file in place outside a driver
     * is not seen (`cache:clear` after hand-editing a file).
     *
     * @return array{string, int, string}
     */
    private function quickSignature(string $generation, string $dir): array
    {
        clearstatcache(true, $dir);

        return [$generation, (int) @filemtime($dir), $this->pathKey];
    }

    /** @return list<array<string, mixed>> every record of the type, with `_id` */
    private function loadRecords(string $type, string $dir): array
    {
        if ($this->cacheDir === null) {
            return $this->readRecords(glob($dir . '/*.json') ?: []);
        }

        // The generation is read before the records: a save after this point moves it on.
        $generation = $this->generation($type);
        $quick = $this->quickSignature($generation, $dir);
        $cacheFile = $this->cacheFile($type);

        $raw = @file_get_contents($cacheFile);
        $cached = $raw === false ? null : json_decode($raw, true);
        if (is_array($cached) && is_array($cached['records'] ?? null)) {
            // A directory mtime has whole-second resolution: a cache built in the second the
            // directory last changed cannot tell a file added later in that second. Such a
            // cache is checked against every record file until it is rebuilt.
            // The cache file's own mtime (the file system's clock, like the directory's) says when it was built.
            clearstatcache(true, $cacheFile);
            $settled = (int) @filemtime($cacheFile) > $quick[1];
            if ($settled && ($cached['quick'] ?? null) === $quick) {
                return $cached['records'];
            }
            if (isset($cached['signature']) && $cached['signature'] === $this->signature($generation, $dir, glob($dir . '/*.json') ?: [])) {
                if (!$settled && time() > $quick[1] && ($cached['quick'] ?? null) === $quick) {
                    // Unchanged and past that second now: trust the cheap check from here on.
                    $this->writeCache($cacheFile, ['quick' => $quick, 'signature' => $cached['signature'], 'records' => $cached['records']]);
                }

                return $cached['records'];
            }
        }

        $files = glob($dir . '/*.json') ?: [];
        $signature = $this->signature($generation, $dir, $files);
        $records = $this->readRecords($files);
        // Changed while reading: use what was read, but do not cache it.
        if ($this->signature($this->generation($type), $dir, glob($dir . '/*.json') ?: []) === $signature) {
            $this->writeCache($cacheFile, ['quick' => $quick, 'signature' => $signature, 'records' => $records]);
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
     * as numbers (numeric text too), the query's text fields (Query::compareAsText()) by the text
     * rule ("Über" and "über" are one group, shown as the first one met; COUNT(DISTINCT), MIN and
     * MAX alike), other values as stored; having, then the orders, then limit and offset.
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
            $identity = [];
            foreach ($aggregate->groups() as $field) {
                $value = $this->aggregateValue($item, $field, $query);
                $values[$field] = isset($numeric[$field]) ? self::numberOrAsStored($value) : $value;
                $identity[] = self::groupIdentity($values[$field], $query->comparesAsText($field));
            }
            $key = self::key($identity);
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

        // Group fields and MIN/MAX of a text field order by the text rule.
        $textNames = [];
        foreach ($aggregate->groups() as $field) {
            $textNames[$field] = $query->comparesAsText($field);
        }
        foreach ($aggregate->measures() as $measure) {
            $textNames[$measure['as']] = in_array($measure['fn'], ['min', 'max'], true) && $query->comparesAsText((string) $measure['field']);
        }
        $orders = $aggregate->orders();
        usort($rows, static function (array $a, array $b) use ($orders, $textNames): int {
            foreach ($orders as $order) {
                $cmp = self::compareNullsFirst($a[$order['name']], $b[$order['name']], $textNames[$order['name']] ?? false);
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
        $text = !$asNumbers && $query->comparesAsText((string) $measure['field']);
        $values = [];
        foreach ($items as $item) {
            $value = $this->aggregateValue($item, (string) $measure['field'], $query);
            $value = $asNumbers ? self::number($value) : $value;
            if ($value !== null && is_scalar($value)) {
                $values[] = $value;
            }
        }

        return match ($measure['fn']) {
            'countDistinct' => count(array_unique(array_map(static fn (mixed $v): string => self::key([self::groupIdentity($v, $text)]), $values))),
            'sum' => array_sum($values),
            'avg' => $values === [] ? null : array_sum($values) / count($values),
            'min' => $values === [] ? null : array_reduce($values, static fn ($carry, $v) => $carry === null || self::compare($v, $carry, $text) < 0 ? $v : $carry),
            'max' => $values === [] ? null : array_reduce($values, static fn ($carry, $v) => $carry === null || self::compare($v, $carry, $text) > 0 ? $v : $carry),
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
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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

    /**
     * What tells a group value apart: the value, or for a text field's text its key under the
     * text rule (TextMatch::key(), as hex in a list so it never meets a stored value).
     */
    private static function groupIdentity(mixed $value, bool $text): mixed
    {
        return $text && is_string($value) ? [bin2hex(TextMatch::key($value))] : $value;
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
    private static function compareNullsFirst(mixed $a, mixed $b, bool $text = false): int
    {
        if ($a === null || $b === null) {
            return ($a !== null) <=> ($b !== null);
        }

        return self::compare($a, $b, $text);
    }

    /**
     * Numbers by value, text by its bytes (as SQL's binary comparison), or by the text rule for a
     * text field ($text), anything else by PHP's order.
     */
    private static function compare(mixed $a, mixed $b, bool $text = false): int
    {
        if (is_string($a) && is_string($b)) {
            return $text ? TextMatch::compare($a, $b) : strcmp($a, $b) <=> 0;
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

        // A save writes the fields it is given and keeps the others (0.78, as SQL keeps the columns
        // an UPDATE does not name): the stored file is read and merged under the type's lock, so a
        // save from another process in between is not undone. A file that does not hold a JSON
        // object (unreadable, half-written by hand) is replaced by the saved data, as before 0.78.
        $this->exclusive($type, function () use ($path, $data): void {
            $raw = is_file($path) ? @file_get_contents($path) : false;
            $stored = $raw === false ? null : json_decode($raw, true);
            if (is_array($stored) && ($stored === [] || !array_is_list($stored))) {
                $data = self::merged($stored, $data);
            }
            // Stable bytes (0.78): umlauts and slashes as they are, 2.0 stays 2.0, a trailing newline.
            // The key order is the caller's (DataManager writes a type's records in its canonical order).
            $json = json_encode($data, self::RECORD_FLAGS) . "\n";

            // Write a temp file next to it, then rename: readers (and exports) only
            // ever see the old or the new record, never half of one.
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException("Could not write {$path}.");
            }
        });

        $this->bumpGeneration($type);
        $this->clearCache($type);
    }

    /**
     * The stored record with $data laid over it: the save's keys in its order, then the stored keys
     * the save does not name, in their stored order. DataManager's saves name every key of the
     * type, so its files keep the canonical order with other keys (a property removed from a model
     * class, a key added by hand) last; a direct partial save ends with the keys it did not name.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function merged(array $stored, array $data): array
    {
        return $data + $stored;
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

    /** A read, compare and write of the stored record under the type's lock (exclusive()). */
    public function updateIfVersion(string $type, string|int $id, array $fields, string $versionField, ?int $expected, string $idField = 'uuid'): bool
    {
        self::assertSafe($type, $id);
        if ($fields === [] || array_key_exists($idField, $fields)) {
            throw new \InvalidArgumentException('updateIfVersion() needs the fields to write, without the id.');
        }

        return $this->exclusive($type, function () use ($type, $id, $fields, $versionField, $expected, $idField): bool {
            $stored = $this->findOne($type, $id, $idField);
            if ($stored === null || !self::holdsVersion($stored[$versionField] ?? null, $expected)) {
                return false;
            }
            $this->save($type, $id, array_replace($stored, $fields), $idField);

            return true;
        });
    }

    /** A read, an addition and a write under the type's lock (exclusive()). AtomicCounters, @since 0.78. */
    public function increment(string $table, string|int $id, string $field, int $by, array $insert, ?\Preflow\Data\CounterReset $reset = null, string $idField = 'uuid'): int
    {
        self::assertSafe($table, $id);

        return $this->exclusive($table, fn (): int => \Preflow\Data\Counters::step($this, $table, $id, $field, $by, $insert, $reset, $idField));
    }

    /** As SQL compares it: no value (null, '') only for null, else the canonical text of the number. */
    private static function holdsVersion(mixed $stored, ?int $expected): bool
    {
        $none = $stored === null || $stored === '';

        return $expected === null ? $none : !$none && is_scalar($stored) && (string) $stored === (string) $expected;
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

        // Under the type's lock: a save in progress (read, merge, write) finishes first, so it
        // cannot write the deleted record's fields back. No folder: nothing to delete (and none made).
        if (is_dir($this->typeDir($type))) {
            $this->exclusive($type, static function () use ($path): void {
                if (file_exists($path)) {
                    unlink($path);
                }
            });
        }

        $this->bumpGeneration($type);
        $this->clearCache($type);
    }

    /** One file each, one cache generation for all. @since 0.77 */
    public function deleteMany(string $type, array $ids, string $idField = 'uuid'): void
    {
        foreach ($ids as $id) {
            self::assertSafe($type, $id);
        }
        if (is_dir($this->typeDir($type))) {
            $this->exclusive($type, function () use ($type, $ids): void {
                foreach ($ids as $id) {
                    $path = $this->filePath($type, $id);
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
            });
        }
        $this->bumpGeneration($type);
        $this->clearCache($type);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return self::safeName($type) && self::safeName((string) $id) && file_exists($this->filePath($type, $id));
    }

    /** Any key is a column: a JSON record takes every key. */
    public function hasColumn(string $type, string $column): bool
    {
        return true;
    }

    /** Whether the type has its directory (made by its first write, kept when its last record is deleted). */
    public function hasTable(string $type): bool
    {
        return self::safeName($type) && is_dir($this->typeDir($type));
    }

    public function lastInsertId(): string|int
    {
        return '';
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

        // A text field's given values in their equality form, once per query (not per row).
        $given = [];
        foreach ($wheres as $i => $where) {
            $given[$i] = ($query?->comparesAsText($where['field']) ?? false) ? self::givenForms($where['value']) : null;
        }

        return array_values(array_filter($items, function (array $item) use ($wheres, $query, $given) {
            $result = true;

            foreach ($wheres as $i => $where) {
                $match = $this->evaluateCondition($item, $where, $query, $given[$i]);

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
            new Locales($localization['available'], $localization['default']),
            $localization['locale'],
        );
    }

    /** @param array{one: ?string, set: array<string, true>}|null $given a text field's given forms (givenForms()), null for any other field */
    private function evaluateCondition(array $item, array $where, ?Query $query = null, ?array $given = null): bool
    {
        $fieldValue = $this->value($item, $where['field'], $query);
        $condValue = $where['value'];
        $text = $given !== null;

        $operator = $where['operator'];
        if ($operator === 'IN' || $operator === 'NOT IN') {
            if ($operator === 'NOT IN' && (array) $condValue === []) {
                return true; // as SQL (1 = 1): an empty NOT IN takes every row, null fields too
            }
            // As SQL: a missing (NULL) field is in no list and not "not in" one either.
            if (!is_scalar($fieldValue)) {
                return false;
            }
            $listed = self::listed($fieldValue, (array) $condValue, $given);

            return $operator === 'IN' ? $listed : !$listed;
        }
        if ($condValue === null && !in_array($operator, ['=', '!=', '<>'], true)) {
            return false; // as SQL: only (not) equal to NULL matches anything
        }

        if ($text && is_string($fieldValue) && is_string($condValue) && in_array($operator, ['>', '>=', '<', '<='], true)) {
            $order = TextMatch::compare($fieldValue, $condValue);

            return match ($operator) {
                '>' => $order > 0,
                '>=' => $order >= 0,
                '<' => $order < 0,
                default => $order <= 0,
            };
        }

        return match ($operator) {
            '=' => self::equalsAs($given, $fieldValue, $condValue),
            // As SQL: != null is IS NOT NULL, and a NULL field is never "not equal" to a value.
            '!=', '<>' => $condValue === null ? $fieldValue !== null : $fieldValue !== null && !self::equalsAs($given, $fieldValue, $condValue),
            '>' => $fieldValue > $condValue,
            '>=' => $fieldValue >= $condValue,
            '<' => $fieldValue < $condValue,
            '<=' => $fieldValue <= $condValue,
            'LIKE' => $fieldValue !== null && $this->matchLike((string) $fieldValue, (string) $condValue),
            'NOT LIKE' => $fieldValue !== null && !$this->matchLike((string) $fieldValue, (string) $condValue),
            default => false,
        };
    }

    /**
     * Whether a stored value equals a given one as SQL compares a stored 1/0 or number with a
     * parameter (0.78: JSON files hold true/false and numbers): a yes/no value equals 1, '1' and
     * 'true' (true) or 0, '0', '' and 'false' (false); a number equals a number or numeric text of
     * the same value ('2' = 2, '2.5' = 2.5, '02' = 2). Text to text stays byte for byte, and text
     * that is no number never equals a number. null equals only null (where(field, null) is IS NULL).
     */
    private static function sqlEquals(mixed $stored, mixed $given): bool
    {
        if ($stored === $given) {
            return true;
        }
        if ($stored === null || $given === null) {
            return false;
        }
        if (is_bool($stored) || is_bool($given)) {
            [$bool, $other] = is_bool($stored) ? [$stored, $given] : [$given, $stored];
            if (is_bool($other)) {
                return false;
            }
            if (is_int($other) || is_float($other)) {
                return $other == ($bool ? 1 : 0);
            }

            return in_array($other, $bool ? ['1', 'true'] : ['0', '', 'false'], true);
        }
        $storedNumber = is_int($stored) || is_float($stored);
        $givenNumber = is_int($given) || is_float($given);
        if ($storedNumber && $givenNumber) {
            return $stored == $given;
        }
        if ($storedNumber && is_string($given)) {
            return is_numeric($given) && (float) $stored === (float) $given;
        }
        if ($givenNumber && is_string($stored)) {
            return is_numeric($stored) && (float) $stored === (float) $given;
        }

        return false;
    }

    /**
     * A text field's given value(s) in their equality form (TextMatch::equalityForm()): "one" for
     * a text given to = or !=, "set" for the listed values of IN, NOT IN and HAS (as text,
     * listText()).
     *
     * @return array{one: ?string, set: array<string, true>}
     */
    private static function givenForms(mixed $value): array
    {
        $set = [];
        foreach (is_array($value) ? $value : [] as $listed) {
            if (is_scalar($listed)) {
                $set[TextMatch::equalityForm(self::listText($listed))] = true;
            }
        }

        return ['one' => is_string($value) ? TextMatch::equalityForm($value) : null, 'set' => $set];
    }

    /**
     * "=" on a field: for a text field ($given, see givenForms()) where both values are text, by
     * the text rule (as TextMatch::equals()); else as SQL compares (sqlEquals()).
     *
     * @param array{one: ?string, set: array<string, true>}|null $given
     */
    private static function equalsAs(?array $given, mixed $stored, mixed $value): bool
    {
        return $given !== null && $given['one'] !== null && is_string($stored)
            ? TextMatch::equalityForm($stored) === $given['one']
            : self::sqlEquals($stored, $value);
    }

    /**
     * Whether a scalar stored value is in the list (IN): compared as text (listText()), by the
     * text rule for a text field's text ($given, see givenForms()).
     *
     * @param array<mixed> $list
     * @param array{one: ?string, set: array<string, true>}|null $given
     */
    private static function listed(bool|int|float|string $stored, array $list, ?array $given): bool
    {
        if ($given !== null && is_string($stored)) {
            return isset($given['set'][TextMatch::equalityForm($stored)]);
        }

        return in_array(self::listText($stored), array_map(self::listText(...), array_filter($list, 'is_scalar')), true);
    }

    /** A value as IN compares it: as text, a yes/no value as '1' or '0' (as SQL stores it). */
    private static function listText(bool|int|float|string $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function applyAnyGroups(array $items, Query $query): array
    {
        foreach ($query->getAnyGroups() as $group) {
            $given = [];
            foreach ($group as $c => $condition) {
                $given[$c] = $query->comparesAsText($condition['field']) ? self::givenForms($condition['value']) : null;
            }
            $items = array_values(array_filter($items, function (array $item) use ($group, $query, $given) {
                foreach ($group as $c => $condition) {
                    if ($this->matchesAny($this->value($item, $condition['field'], $query), $condition, $given[$c])) {
                        return true;
                    }
                }
                return false;
            }));
        }

        return $items;
    }

    /**
     * @param array{field: string, operator: string, value: mixed} $condition
     * @param array{one: ?string, set: array<string, true>}|null $given a text field's given forms (givenForms()), null for any other field
     */
    private function matchesAny(mixed $fieldValue, array $condition, ?array $given = null): bool
    {
        $value = $condition['value'];

        return match ($condition['operator']) {
            'CONTAINS' => self::containsText($fieldValue, (string) $value),
            'IN' => is_scalar($fieldValue) && self::listed($fieldValue, (array) $value, $given),
            'HAS' => $this->holdsAny($fieldValue, array_map('strval', (array) $value), $given),
            default => self::equalsAs($given, $fieldValue, $value),
        };
    }

    /**
     * CONTAINS under the text-matching rule (TextMatch): a text (or number) that holds the term,
     * or a list any of whose values does (a tags field a model saved as an array). SQL storage
     * keeps such a list as JSON text and finds the same records (since 0.77).
     */
    private static function containsText(mixed $fieldValue, string $term): bool
    {
        if (is_array($fieldValue)) {
            foreach ($fieldValue as $item) {
                if (self::containsText($item, $term)) {
                    return true;
                }
            }

            return false;
        }

        return is_scalar($fieldValue) && TextMatch::contains((string) $fieldValue, $term);
    }

    /**
     * HAS: the stored id, or one of the stored list's ids, is one of $ids. A text field's single
     * value ($given) compares by the text rule, as its column does on SQL; a list (tags, a JSON
     * array) compares exactly on every driver.
     *
     * @param list<string> $ids
     * @param array{one: ?string, set: array<string, true>}|null $given a text field's forms of $ids (givenForms())
     */
    private function holdsAny(mixed $stored, array $ids, ?array $given = null): bool
    {
        if ($ids === [] || $stored === null || $stored === '') {
            return false;
        }
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        if ($given !== null && is_string($stored) && !is_array($decoded)) {
            return isset($given['set'][TextMatch::equalityForm($stored)]);
        }
        if (is_array($stored)) {
            $held = array_map('strval', array_filter($stored, 'is_scalar'));
        } else {
            $held = [(string) $stored];
            if (is_array($decoded)) {
                $held = array_map('strval', array_filter($decoded, 'is_scalar'));
            }
        }

        return array_intersect($ids, $held) !== [];
    }

    /** LIKE under the text-matching rule (TextMatch); no escape character, as on SQL. */
    private function matchLike(string $value, string $pattern): bool
    {
        return TextMatch::like($value, $pattern);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function applySearch(array $items, string $term, array $fields, ?Query $query = null): array
    {
        return array_values(array_filter($items, function (array $item) use ($term, $fields, $query) {
            $searchIn = $fields ?: array_keys($item);

            foreach ($searchIn as $field) {
                $value = $this->value($item, $field, $query);
                if (is_string($value)) {
                    if (TextMatch::contains($value, $term)) {
                        return true;
                    }
                } elseif (is_array($value) && self::containsText($value, $term)) {
                    return true; // a list: SQL finds the term in its JSON text
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

        // Each row's sort values once: a text field (Query::compareAsText(), not numeric) by its
        // key under the text rule, null and missing as '' (first); others as stored.
        $values = [];
        $keys = [];
        foreach ($items as $i => $item) {
            foreach ($orderBy as $o => $order) {
                $value = $this->value($item, $order['field'], $query) ?? '';
                $values[$i][$o] = $value;
                if (!($order['numeric'] ?? false) && is_scalar($value) && ($query?->comparesAsText($order['field']) ?? false)) {
                    $keys[$i][$o] = TextMatch::key((string) $value);
                }
            }
        }
        $indexes = array_keys($items);
        usort($indexes, static function (int|string $a, int|string $b) use ($orderBy, $values, $keys): int {
            foreach ($orderBy as $o => $order) {
                $aVal = $values[$a][$o];
                $bVal = $values[$b][$o];
                $cmp = match (true) {
                    isset($keys[$a][$o], $keys[$b][$o]) => strcmp($keys[$a][$o], $keys[$b][$o]) <=> 0,
                    is_numeric($aVal) && is_numeric($bVal) => $aVal <=> $bVal,
                    default => strcmp((string) $aVal, (string) $bVal),
                };

                if ($cmp !== 0) {
                    return $order['direction'] === SortDirection::Desc ? -$cmp : $cmp;
                }
            }
            return 0;
        });

        return array_map(static fn (int|string $i): array => $items[$i], $indexes);
    }
}
