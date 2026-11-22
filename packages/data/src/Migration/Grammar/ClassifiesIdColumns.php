<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

use Preflow\Data\IdKind;

/**
 * A grammar that says what kind of id a key column holds, from the column's type as its
 * columnTypes() reports it. PdoDriver asks it for StatesIdKinds (DataManager::idKind()); a grammar
 * without it leaves the answer to the type. A grammar for another server implements it by that
 * server's rules: Int where the server compares a text id with the column as a number (a reserved
 * text id would then match a number), String where it compares as text, null where neither holds.
 *
 * @since 0.82.0
 */
interface ClassifiesIdColumns
{
    /**
     * The id kind a key column of this type holds, or null to leave it to the type.
     *
     * @param string $type the lower-case column type, as columnTypes() reports it
     * @since 0.82.0
     */
    public function idKindOfColumn(string $type): ?IdKind;
}
