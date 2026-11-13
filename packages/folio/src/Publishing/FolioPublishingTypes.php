<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Folio\Content\TypeCatalog;
use Preflow\Publishing\PublishingTypes;

/**
 * Which types publish, as Folio lists them: the type's "publishing" flag, else on for a type with a
 * frontend route and every singleton (TypeCatalog). A type Folio does not list does not publish.
 */
final class FolioPublishingTypes implements PublishingTypes
{
    public function __construct(
        private readonly TypeCatalog $catalog,
    ) {}

    public function publishes(string $type): bool
    {
        return $this->catalog->get($type)?->publishing ?? false;
    }

    public function publishing(): array
    {
        $keys = [];
        foreach ($this->catalog->all() as $listing) {
            if ($listing->publishing) {
                $keys[] = $listing->key;
            }
        }

        return $keys;
    }
}
