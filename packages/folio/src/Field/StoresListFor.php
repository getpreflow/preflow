<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * For field types whose value is a list for some configs only: a multiple relation or asset
 * holds a list of ids, a single one one id. Folio tells the data package
 * (FieldKinds::listWhen()), so a save writes such a value as a list in a JSON file, also when it
 * was given as JSON text; SQL storage keeps it as JSON text. A type whose value is always a list
 * implements StoresList instead. Extension field types opt in by implementing it.
 *
 * @since 0.89
 */
interface StoresListFor
{
    /** @param array<string, mixed> $config the field's config from the model file */
    public function storesList(array $config): bool;
}
