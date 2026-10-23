<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\QueryBuilder;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;

/**
 * A type's list order from its model's "list" block: orderable lists by
 * manual position (_order, then id); "sort": "-field" by that field, a
 * leading "-" meaning descending. Shared by the admin list and Collections.
 */
final class ListOrder
{
    public const string POSITION = '_order';

    /** @param array<string, mixed> $listConfig */
    public static function apply(QueryBuilder $query, TypeDefinition $def, array $listConfig): QueryBuilder
    {
        if (($listConfig['orderable'] ?? false) === true) {
            // Numeric: SQL-stored types keep positions in TEXT columns.
            return $query->orderBy(self::POSITION, numeric: true)->orderBy($def->idField);
        }

        $sort = (string) ($listConfig['sort'] ?? '');
        if ($sort !== '') {
            $desc = str_starts_with($sort, '-');
            $field = ltrim($sort, '-');
            // Number fields compare as numbers: SQL-stored types keep them in TEXT columns too.
            $numeric = ($def->fields[$field]->type ?? null) === 'number';
            $query = $query->orderBy($field, $desc ? SortDirection::Desc : SortDirection::Asc, $numeric);
        }

        return $query;
    }
}
