<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** What only the site knows: whether a record a redirect points at is on the site. */
interface TargetIsLive
{
    /** Whether the record is published, so its address answers. */
    public function isLive(string $type, string $id): bool;
}
