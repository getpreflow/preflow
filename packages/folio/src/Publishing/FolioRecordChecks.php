<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Publishing\RecordChecks;

/**
 * The rules a publish and RecordWriter's saves check while Folio is installed: publishing's core
 * checks (CoreRecordChecks: the core's rules of each field, a read-only field unchecked) plus
 * Folio's address rules ("slug" for the fields of a type's public address), as
 * RecordValidator::storedChecks() builds them. Since 0.93 not the form's input rules: a date
 * server code stored with an offset publishes as saveType() takes it. Folio's own form passes
 * FolioFormChecks instead (SaveOptions::$checks).
 */
final class FolioRecordChecks implements RecordChecks
{
    public function __construct(
        private readonly RecordValidator $validator,
    ) {}

    public function validate(DynamicRecord $record): void
    {
        $this->validator->storedChecks()->validate($record);
    }
}
