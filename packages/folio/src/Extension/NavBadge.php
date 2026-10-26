<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A count badge on a sidebar item: on a type customization (the type's item) or on a
 * #[FolioScreen] class (the screen's item). The method is public, non-static, fn(): ?int;
 * null, 0 or anything but an int shows no badge, and it is only called while the item is
 * visible to the signed-in user. One per class; the badges of several customizations of a
 * type add up.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class NavBadge {}
