<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\ChangeDates;
use Preflow\Data\ChangeDateSource;
use Preflow\Data\TypeDefinition;

/**
 * For the change-date backfill (`preflow migrate`, data.change-dates): a published copy was last
 * changed when the publish wrote it, its `_published_at`. Registered by PublishingServiceProvider.
 *
 * @since 0.84.0
 */
final class PublishedTimeSource implements ChangeDateSource
{
    /** @since 0.84.0 */
    public function label(): string
    {
        return 'the publish time';
    }

    /** @since 0.84.0 */
    public function changedAt(TypeDefinition $def, array $rows): array
    {
        $times = [];
        foreach ($rows as $row) {
            $at = ChangeDates::parse($row['_published_at'] ?? null);
            $id = $row[$def->idField] ?? null;
            if ($at !== null && (is_string($id) || is_int($id))) {
                $times[(string) $id] = $at;
            }
        }

        return $times;
    }
}
