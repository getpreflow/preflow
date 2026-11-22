<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Optional for a StorageDriver: the storage says what kind of id it holds for a type, for example
 * by its table's key column, or because it numbers every record itself. DataManager::idKind()
 * gives the type's model class first (its id property holds only its own kind; a storage that
 * says the other kind makes idKind() throw), then asks this, then the type's declared id field.
 *
 * The SQL drivers (PdoDriver) implement it from the key column; the JSON file driver does not (a
 * record's file is named by whatever id it is given). A driver that wraps another (a decorator)
 * must implement it and forward the call, or the inner driver's answer is lost and the
 * declaration decides.
 *
 * @since 0.82.0
 */
interface StatesIdKinds
{
    /**
     * The kind of id this storage holds for $def's records, or null to leave it to the type.
     *
     * @since 0.82.0
     */
    public function idKindOf(TypeDefinition $def): ?IdKind;
}
