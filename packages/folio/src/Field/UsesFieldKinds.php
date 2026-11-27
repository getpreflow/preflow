<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\FieldKinds;

/**
 * Optional for a field type whose value is the core's (0.93): Folio hands it the DataManager's
 * field kinds when it registers the type (FolioServiceProvider), and the type's toStorage(),
 * fromStorage() and the shared part of normalizeInput() then use the value registered there for
 * its key (FieldKinds::valueOf()), so the admin form and an API write store the same. Without
 * kinds (a type built on its own) it uses its own default, the same core value class.
 *
 * @since 0.93.0
 */
interface UsesFieldKinds
{
    public function useFieldKinds(FieldKinds $kinds): void;
}
