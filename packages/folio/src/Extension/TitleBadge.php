<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * Badges after a record's title in the editor, on a type customization: the method is public,
 * non-static, fn(DynamicRecord $record): list<Badge> (or one Badge, or null for none) and runs for
 * a record that is stored (not on "New"). Each Badge is a Folio pill (label escaped, tone as for
 * #[ListColumn]); a type that publishes shows them after its Published / Draft pills. A method
 * that throws, or returns anything else, adds nothing (the editor must open even when an
 * extension's badge is broken). Several methods and customizations may each add some, in
 * extension name order.
 *
 * @since 0.84.0
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class TitleBadge {}
