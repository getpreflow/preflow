<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A card in the dashboard's "Needs you" section, on a type customization: the method is public,
 * non-static and declares the return type ?Preflow\Folio\Dashboard\NeedsYou (one card) or array
 * (a list<NeedsYou>, since 0.89; anything else is a boot error). It is only called for a user who can view the type ({type}.view). Null or an empty list shows
 * nothing, an element that is not a NeedsYou is dropped, and so does a method that throws (the dashboard must render even when an extension's
 * item is broken), including a NeedsYou with an unknown tone. Several methods and customizations
 * may each add cards; they show after Folio's own items in #[DashboardNotice]'s order: by type, in
 * the order the types' customizations were registered, then extension name.
 * #[DashboardNotice] HTML stays above the section. Since 0.76.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class DashboardItem {}
