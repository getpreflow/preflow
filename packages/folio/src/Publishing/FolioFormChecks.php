<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Publishing\RecordChecks;

/**
 * The checks of Folio's own form (since 0.93), which its saves pass as SaveOptions::$checks:
 * RecordValidator's, with a HasStoredRules type's input rule in place of its stored rules (a
 * date: "Y-m-d", or "Y-m-d\TH:i[:s]" with "time", what the editor's input posts) and the address
 * rules. Every other RecordWriter save and every publish run the bound RecordChecks
 * (FolioRecordChecks), which take each shape the type stores.
 *
 * @since 0.93.0
 */
final class FolioFormChecks implements RecordChecks
{
    public function __construct(
        private readonly RecordValidator $validator,
    ) {}

    public function validate(DynamicRecord $record): void
    {
        $this->validator->validate($record);
    }
}
