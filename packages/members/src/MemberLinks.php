<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\DynamicRecord;

/**
 * Where the owner decides about a waiting member, for the owner's notice mail. Optional: without
 * a binding the notice is text only. Folio's members admin binds one (the member's edit page).
 */
interface MemberLinks
{
    /** The absolute URL of the page to accept or decline $member; null for none. */
    public function decisionUrl(DynamicRecord $member): ?string;
}
