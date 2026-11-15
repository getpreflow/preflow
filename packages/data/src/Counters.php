<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * The increment step of AtomicCounters as a read, an addition and a write through any
 * StorageDriver: atomic only when the caller holds the type's lock around it (the JSON driver,
 * a SQL dialect without insert-or-update, DataManager's fallback).
 *
 * @internal
 */
final class Counters
{
    /**
     * Refuses an $insert naming the counter or the id, and a reset writing the counter.
     *
     * @param array<string, mixed> $insert
     * @throws \InvalidArgumentException
     */
    public static function assertArguments(string $field, array $insert, ?CounterReset $reset, string $idField): void
    {
        if (array_key_exists($field, $insert) || array_key_exists($idField, $insert)) {
            throw new \InvalidArgumentException("increment(): the insert values name neither the counter ({$field}) nor the id ({$idField}).");
        }
        if ($reset !== null && array_key_exists($field, $reset->set)) {
            throw new \InvalidArgumentException("increment(): a CounterReset does not write the counter ({$field}); the restart sets it to the increment.");
        }
    }

    /**
     * @param array<string, scalar|null> $insert
     */
    public static function step(StorageDriver $driver, string $table, string|int $id, string $field, int $by, array $insert, ?CounterReset $reset, string $idField): int
    {
        self::assertArguments($field, $insert, $reset, $idField);
        $row = $driver->findOne($table, $id, $idField);
        if ($row === null) {
            $row = [$idField => $id] + $insert + [$field => $by];
        } elseif ($reset !== null && self::below($row[$reset->field] ?? null, $reset->below)) {
            $row = array_replace($row, $reset->set, [$field => $by]);
        } else {
            $row[$field] = self::number($row[$field] ?? null) + $by;
        }
        $driver->save($table, $id, $row, $idField);

        return (int) $row[$field];
    }

    /** As SQL compares: a value without a number (null, '') is below nothing. */
    private static function below(mixed $stored, int $bound): bool
    {
        return is_numeric($stored) && $stored + 0 < $bound;
    }

    private static function number(mixed $stored): int
    {
        return is_numeric($stored) ? (int) ($stored + 0) : 0;
    }
}
