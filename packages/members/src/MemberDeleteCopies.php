<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\TypeDefinition;

/**
 * Optional hook: further definitions that hold copies of a type's records (e.g. Folio's published
 * snapshots), which a member's own deletion plan queries with the type's relation fields so a
 * "restrict" self rule also counts pointers only a copy holds (DataManager::deletePlan()'s $copies).
 * A package that keeps copies binds it (Folio does); without a binding the plan has no copies.
 * Carrying the deletion into the copies is the binding package's own DELETED listener.
 */
interface MemberDeleteCopies
{
    /** @return list<TypeDefinition> */
    public function of(TypeDefinition $def): array;
}
