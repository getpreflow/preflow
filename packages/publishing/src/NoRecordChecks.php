<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/** RecordChecks that check nothing: every record may be published (the default before 0.93; now CoreRecordChecks). */
final class NoRecordChecks implements RecordChecks
{
    public function validate(DynamicRecord $record): void {}
}
