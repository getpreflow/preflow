<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

/**
 * A Dialect that can fail a statement on purpose, so PdoDriver::increment() (AtomicCounters)
 * refuses a count past the 64-bit whole numbers in its one insert-or-update statement: the
 * statement adds only while the stored counter (read as Dialect::wholeNumber() reads it) stays
 * within Add::bound(), and evaluates overflowFailure() otherwise. The failed statement writes
 * nothing, and PdoDriver throws the \OverflowException Add::overflow() words.
 *
 * Without it, increment() adds as before 0.90: past the range SQLite stores a REAL and MySQL
 * fails on a BIGINT column (a DOUBLE column rounds). An optional interface beside Dialect, so a
 * dialect written before it keeps working unchanged; a database whose failed statement aborts
 * the transaction (PostgreSQL) leaves the caller's transaction to roll back.
 *
 * @since 0.90.0
 */
interface FailsOverflow
{
    /**
     * An SQL expression, without placeholders, that fails the statement whenever it is evaluated,
     * and only then (the database must not evaluate it ahead, as a constant, in a CASE branch the
     * row does not take): SQLite's abs() of the smallest integer, MySQL's ~0 + 1.
     */
    public function overflowFailure(): string;

    /** Whether $e is the failure overflowFailure() raises (or the database's own integer overflow). */
    public function isOverflowFailure(\PDOException $e): bool;
}
