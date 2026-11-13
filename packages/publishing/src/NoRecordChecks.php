<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/** The default RecordChecks: every record may be published. */
final class NoRecordChecks implements RecordChecks
{
    public function validate(DynamicRecord $record): void {}
}
