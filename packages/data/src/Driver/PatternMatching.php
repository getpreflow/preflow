<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

/**
 * A Dialect that writes its case-insensitive match per pattern (where LIKE / NOT LIKE, search(),
 * CONTAINS), not only as an operator (Dialect::likeOperator()): it sees the bound pattern and may
 * choose the SQL by it. SQLite's does: its native LIKE folds ASCII case only, so a pattern of
 * ASCII characters keeps it (fast) and any other goes through the Unicode ulike() function.
 * QueryCompiler asks for it where the dialect has it; a dialect without it gets the operator form
 * ("{column} LIKE ?", "{column} NOT LIKE ?", with " ESCAPE '!'" for CONTAINS).
 *
 * @since 0.77
 */
interface PatternMatching
{
    /**
     * SQL matching $column against the pattern, with exactly one "?" placeholder, which the
     * caller binds to $pattern. % matches any text, _ one character, $escape (one character, or
     * null for none) takes the next one literally; text is compared by the text-matching rule
     * (StorageDriver: case ignored in every alphabet, accents count). $not negates it (NULL stays
     * NULL, as with NOT LIKE).
     */
    public function likeSql(string $column, string $pattern, bool $not = false, ?string $escape = null): string;
}
