<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;
use Preflow\Validation\ValidationException;

/**
 * The rules a record must pass before it is published (a draft save skips them), and before
 * RecordWriter saves a record of a type that does not publish. The default (since 0.93) is
 * CoreRecordChecks, the core's rules of each field, as saveType() runs them; an admin binds its
 * own (Folio: those plus its address rules). NoRecordChecks checks nothing.
 */
interface RecordChecks
{
    /** @throws ValidationException naming each failed field */
    public function validate(DynamicRecord $record): void;
}
