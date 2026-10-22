<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A count badge on the type's sidebar item. The method is fn(): ?int; null or 0 shows no
 * badge, and it is only called while the item is visible to the signed-in user. One per
 * class; the badges of several customizations of a type add up.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class NavBadge {}
