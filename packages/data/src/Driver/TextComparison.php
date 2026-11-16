<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

/**
 * A dialect that can compare any value by the text rule (ignore case, respect accents;
 * TextMatch::equals()) in SQL; matching (LIKE, ignoring accents too) is PatternMatching's.
 * QueryCompiler wraps a translatable text field's locale value with it (a JSON value carries no
 * column collation) in =, !=, IN, NOT IN, range comparisons, ORDER BY, grouping and MIN/MAX, and
 * orders every text field's values with textOrder(). A plain column is left to its own collation
 * (TextCollations), so its indexes stay usable. A dialect without it compares those values as
 * the database does.
 *
 * @since 0.78
 */
interface TextComparison
{
    /** SQL for the value of $expression compared by the rule. */
    public function textValue(string $expression): string;

    /**
     * SQL that ORDER BY sorts by to order a text field's values by the rule: $expression is a
     * text field's column or its textValue(). Where the database sorts that already by its
     * collation, $expression itself.
     */
    public function textOrder(string $expression): string;
}
