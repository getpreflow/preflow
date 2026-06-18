<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

final readonly class TypeListing
{
    public function __construct(
        public string $key,
        public string $label,
    ) {}
}
