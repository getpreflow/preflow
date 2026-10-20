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
            return $query->orderBy(self::POSITION)->orderBy($def->idField);
        }

        $sort = (string) ($listConfig['sort'] ?? '');
        if ($sort !== '') {
            $desc = str_starts_with($sort, '-');
            $query = $query->orderBy(ltrim($sort, '-'), $desc ? SortDirection::Desc : SortDirection::Asc);
        }

        return $query;
    }
}
