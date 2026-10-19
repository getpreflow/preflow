<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;

final readonly class ResolvedRecord
{
    public function __construct(
        public DynamicRecord $record,
        public FrontendRoute $route,
    ) {}
}
