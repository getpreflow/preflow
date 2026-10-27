<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Data\TypeDefinition;
use Preflow\Folio\Publishing\PublishedCopies;
use Preflow\Members\MemberDeleteCopies;

/**
 * A member's own deletion counts pointers in published snapshots too (as a staff delete does),
 * so a "restrict" self rule refuses when only the published copy still points at the member.
 */
final class FolioMemberDeleteCopies implements MemberDeleteCopies
{
    public function __construct(private readonly PublishedCopies $copies) {}

    public function of(TypeDefinition $def): array
    {
        return $this->copies->of($def);
    }
}
