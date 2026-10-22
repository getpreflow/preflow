<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * Runs before an admin delete: fn(DynamicRecord $record): void. Throwing
 * Customization\Refused cancels the delete and shows its message.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class BeforeDelete
{
}
