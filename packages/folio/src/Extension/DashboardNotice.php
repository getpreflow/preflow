<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A notice above the dashboard's cards, on a type customization: the method is public,
 * non-static, fn(): ?string and returns HTML that Folio prints as it is (unescaped), so the
 * method escapes its own content, typically by rendering a Twig template. It is only called for
 * a user who can view the type ({type}.view). Null or '' shows nothing, and so does a method
 * that throws (the dashboard must render even when an extension's notice is broken). Several
 * methods and customizations may each add one; they show grouped by type, in extension name
 * order. Since 0.53.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class DashboardNotice {}
