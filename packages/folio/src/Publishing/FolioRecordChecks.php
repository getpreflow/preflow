<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Publishing\RecordChecks;

/** The rules a publish checks: Folio's field types and the type's rules (RecordValidator). */
final class FolioRecordChecks implements RecordChecks
{
    public function __construct(
        private readonly RecordValidator $validator,
    ) {}

    public function validate(DynamicRecord $record): void
    {
        $this->validator->validate($record);
    }
}
