<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;
use Preflow\Validation\ValidationException;

/**
 * The rules a record must pass before it is published (a draft save skips them). The default
 * checks nothing; an admin binds its own (Folio checks its field types and the type's rules).
 */
interface RecordChecks
{
    /** @throws ValidationException naming each failed field */
    public function validate(DynamicRecord $record): void;
}
