<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A notice above a type's list, on a type customization: the method is public, non-static,
 * fn(): ?string and returns HTML that Folio prints as it is (unescaped), so the method escapes
 * its own content, typically by rendering a Twig template. Null or '' shows nothing, and so does
 * a method that throws (the list must render even when an extension's notice is broken). Only
 * on the full list page, not on its live-search refreshes. Several methods and several
 * customizations of a type may each add one; they show in extension name order. Since 0.53.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ListNotice {}
