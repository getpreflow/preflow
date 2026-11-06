<?php

declare(strict_types=1);

namespace Preflow\Testing\Fast;

/**
 * A database whose tables are part of a set-up snapshot (SetupSnapshots::database()).
 */
interface SnapshotDatabase
{
    /**
     * Every table's definition and checksum: part of the state a step's key is made of.
     *
     * @return array<string, mixed>
     */
    public function state(): array;

    /**
     * Every table's definition and rows, as restore() takes them back.
     *
     * @return array<string, mixed>
     */
    public function capture(): array;

    /**
     * Exactly the captured tables afterwards: other tables go, changed ones are recreated.
     *
     * @param array<string, mixed> $tables what capture() returned
     */
    public function restore(array $tables): void;

    /** No tables afterwards. */
    public function empty(): void;
}
