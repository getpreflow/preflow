<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\SortDirection;
use Preflow\Data\StorageDriver;

final class JsonFileDriver implements StorageDriver
{
    public function __construct(
        private readonly string $basePath,
    ) {}

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

        $items = [];
        foreach (glob($dir . '/*.json') as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $data['_id'] = basename($file, '.json');
            $items[] = $data;
        }

        // Apply search
        if ($query->getSearchTerm() !== null) {
            $items = $this->applySearch($items, $query->getSearchTerm(), $query->getSearchFields());
        }

        // Apply where conditions
        $items = $this->applyWheres($items, $query->getWheres());

        // Apply ordering
        $items = $this->applyOrderBy($items, $query->getOrderBy());

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
        file_put_contents($path, $json);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $path = $this->filePath($type, $id);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return file_exists($this->filePath($type, $id));
    }

    public function lastInsertId(): string|int
    {
        return '';
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
    private function applyWheres(array $items, array $wheres): array
    {
        if ($wheres === []) {
            return $items;
        }

        return array_values(array_filter($items, function (array $item) use ($wheres) {
            $result = true;

            foreach ($wheres as $i => $where) {
                $match = $this->evaluateCondition($item, $where);

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
    private function evaluateCondition(array $item, array $where): bool
    {
        $fieldValue = $item[$where['field']] ?? null;
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
    private function applySearch(array $items, string $term, array $fields): array
    {
        $term = mb_strtolower($term);

        return array_values(array_filter($items, function (array $item) use ($term, $fields) {
            $searchIn = $fields ?: array_keys($item);

            foreach ($searchIn as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    if (str_contains(mb_strtolower($item[$field]), $term)) {
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
    private function applyOrderBy(array $items, array $orderBy): array
    {
        if ($orderBy === []) {
            return $items;
        }

        usort($items, function (array $a, array $b) use ($orderBy) {
            foreach ($orderBy as $order) {
                $field = $order['field'];
                $aVal = $a[$field] ?? '';
                $bVal = $b[$field] ?? '';
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
