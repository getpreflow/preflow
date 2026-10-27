<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\TypeDefinition;

/**
 * Every record table the data layer makes from a type definition, as the packages that sync
 * them register it (Folio's types and their shadows, codes, members, media, …): what
 * `preflow migrate` creates is what a conversion of existing tables has to find.
 *
 * @phpstan-type Entry array{def: TypeDefinition, extra: list<string>, typed: bool, unique: bool}
 */
final class RecordTables
{
    /** @var array<string, \Closure(): list<Entry>> */
    private array $sources = [];

    /**
     * @param \Closure(): list<Entry> $definitions called on each all(), so it sees the definitions of the moment
     */
    public function add(string $source, \Closure $definitions): void
    {
        if (isset($this->sources[$source])) {
            throw new \RuntimeException("Record tables [{$source}] are already registered.");
        }
        $this->sources[$source] = $definitions;
    }

    /** @return list<Entry> */
    public function all(): array
    {
        $all = [];
        foreach ($this->sources as $definitions) {
            array_push($all, ...$definitions());
        }

        return $all;
    }

    /**
     * One table: typed columns unless $typed is false (history that must never block a conversion),
     * unique indexes unless $unique is false (copies of records, such as published snapshots).
     *
     * @param list<string> $extra columns besides the definition's fields
     * @return Entry
     */
    public static function entry(TypeDefinition $def, array $extra = [], bool $typed = true, bool $unique = true): array
    {
        return ['def' => $def, 'extra' => $extra, 'typed' => $typed, 'unique' => $unique];
    }
}
