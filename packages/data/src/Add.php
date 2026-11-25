<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * In the values of a conditional write (DataManager::updateWhen()): add $amount to the stored
 * number instead of writing a value. `['uses' => new Add(1)]` counts one use, `new Add(-1)` gives
 * one back. The stored value is read as Condition::wholeNumber() reads it; a field without a
 * value counts from 0. A SQL storage adds in the UPDATE itself, so a concurrent write is never
 * lost.
 *
 * A sum past the 64-bit whole numbers (PHP_INT_MIN … PHP_INT_MAX) is refused on every storage
 * (since 0.90): updateWhen() throws an \OverflowException naming the field and the stored value
 * stays as it was. A compiled UPDATE (Driver\ConditionCompiler) only adds while the sum stays in
 * range (SQLite would store it as a REAL, MySQL's strict mode fail the statement), and
 * DataManager then tells the refusal from a condition that does not hold.
 *
 * @since 0.89.0
 */
final readonly class Add
{
    public function __construct(public int $amount) {}

    /**
     * The stored value with $amount added: what the write stores.
     *
     * @throws \OverflowException when the sum is past the 64-bit whole numbers ($field names it)
     */
    public function applyTo(mixed $stored, string $field = ''): int
    {
        $base = Condition::wholeNumber($stored) ?? 0;
        $bound = $this->bound();
        if ($bound !== null && ($bound[0] === '<=' ? $base > $bound[1] : $base < $bound[1])) {
            throw $this->overflow($field);
        }

        return $base + $this->amount;
    }

    /**
     * The refusal of a sum past the 64-bit whole numbers, naming $field: what applyTo() throws, and
     * AtomicCounters::increment() for its counter (since 0.90).
     *
     * @since 0.90.0
     */
    public function overflow(string $field = ''): \OverflowException
    {
        $name = $field === '' ? 'the field' : "the field [{$field}]";

        return new \OverflowException("Adding {$this->amount} to {$name} goes past the "
            . ($this->amount > 0 ? 'largest' : 'smallest') . ' whole number the storage keeps (' . ($this->amount > 0 ? PHP_INT_MAX : PHP_INT_MIN)
            . '); nothing was written. Add a smaller amount or reset the field.');
    }

    /**
     * The bound the stored whole number must not pass for the sum to stay in range: the stored
     * value must be <= the bound for a positive amount, >= it for a negative one; null for 0.
     *
     * @return array{0: '<='|'>=', 1: int}|null
     */
    public function bound(): ?array
    {
        return match (true) {
            $this->amount > 0 => ['<=', PHP_INT_MAX - $this->amount],
            $this->amount < 0 => ['>=', PHP_INT_MIN - $this->amount],
            default => null,
        };
    }
}
