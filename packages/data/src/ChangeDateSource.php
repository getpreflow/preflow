<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * One more place the change-date backfill (ChangeDateBackfill, `preflow migrate`'s step
 * data.change-dates) may learn when records written before change dates were last changed. A
 * package registers its own with ChangeDateSources (preflow/publishing: the time a published copy
 * was written); the data layer knows no package's keys. The registered sources are asked in their
 * order, after the activity log and the type's own updated field, before the storage's own write
 * time, the creation time and the migrate's time.
 *
 * @since 0.84.0
 */
interface ChangeDateSource
{
    /** What migrate's summary calls it ("the publish time"). */
    public function label(): string;

    /**
     * When each of the rows was last changed, as this source knows it. A row it cannot tell is left
     * out (the next source decides). It throws when it cannot answer at all: the backfill then
     * writes nothing for the table and says so, so the next run asks again.
     *
     * @param list<array<string, mixed>> $rows the records of $def as stored
     * @return array<string, \DateTimeImmutable> record id => the time
     */
    public function changedAt(TypeDefinition $def, array $rows): array;
}
