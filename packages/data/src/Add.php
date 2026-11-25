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
 * @since 0.89.0
 */
final readonly class Add
{
    public function __construct(public int $amount) {}

    /** The stored value with $amount added: what the write stores. */
    public function applyTo(mixed $stored): int
    {
        return (Condition::wholeNumber($stored) ?? 0) + $this->amount;
    }
}
