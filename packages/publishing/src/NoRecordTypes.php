<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/** The default RecordTypes: no type keeps a manual order. */
final class NoRecordTypes implements RecordTypes
{
    public function orderable(string $type): bool
    {
        return false;
    }
}
