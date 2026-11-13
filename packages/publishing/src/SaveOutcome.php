<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/**
 * What a RecordWriter save did: the record as saved (its id, _version and _order assigned), and
 * whether one of its public addresses left the site with it (a type without publishing whose
 * record stopped being shown: its old links now answer 404 or redirect).
 */
final readonly class SaveOutcome
{
    public function __construct(
        public DynamicRecord $record,
        public bool $addressesLeft,
    ) {}
}
