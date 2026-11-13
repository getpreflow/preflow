<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Folio\Content\TypeCatalog;
use Preflow\Publishing\RecordTypes;

/** Folio's listings: a type is kept in a manual order when its model's "list" says "orderable": true. */
final class FolioRecordTypes implements RecordTypes
{
    public function __construct(
        private readonly TypeCatalog $catalog,
    ) {}

    public function orderable(string $type): bool
    {
        return ($this->catalog->get($type)?->list['orderable'] ?? false) === true;
    }
}
