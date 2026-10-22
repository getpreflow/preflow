<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * Runs after an admin delete: fn(DynamicRecord $record): void (the deleted record).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class AfterDelete
{
}
