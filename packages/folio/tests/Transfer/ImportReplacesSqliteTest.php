<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Transfer;

class ImportReplacesSqliteTest extends ImportReplacesTestCase
{
    protected function storage(): string
    {
        return 'sqlite';
    }

    /** A column the table does not have: the database refuses the row. */
    protected function breakingFields(): array
    {
        return ['no_such_column' => 1];
    }
}
