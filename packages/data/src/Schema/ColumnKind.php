<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

/**
 * How a field's value is stored in a SQL column. Short is a bounded string (unique or indexed
 * fields); Text is everything else, including all structured values.
 */
enum ColumnKind: string
{
    case Text = 'text';
    case Short = 'short';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
}
