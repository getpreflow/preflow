<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;

/**
 * Resolves a human label for a record: the value of its first string field,
 * falling back to the record id. Single source of truth shared by the matrix
 * editor and the record-label API.
 */
final class RecordLabeler
{
    public function label(DynamicRecord $record): string
    {
        foreach ($record->getType()->fields as $name => $def) {
            if ($def->type === 'string') {
                $value = $record->get($name);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
                break;
            }
        }

        return (string) $record->getId();
    }
}
