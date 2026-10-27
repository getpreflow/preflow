<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\Schema\ColumnKind;

/**
 * A field type that says how its value is stored in a SQL column. Folio tells the data package
 * (DataManager::fieldKinds()); a type without it is stored as text.
 */
interface HasColumnKind
{
    /** @param array<string, mixed> $config the field's config from the model file */
    public function columnKind(array $config): ColumnKind;
}
