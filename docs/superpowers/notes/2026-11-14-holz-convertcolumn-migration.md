# holz: switch the indexed-columns migration to Schema::convertColumn() (after 0.75)


Replace the body of `migrations/2026_11_13_120000_indexed_object_columns.php`. The private `$pdo` closure and the raw `ALTER` go away. The length check, the skipping and the exact type (VARCHAR(191) utf8mb4_bin) now come from the framework.

```php
<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

// The object's state, category and owner are indexed (config/models/object.json, Preflow 0.72
// declared indexes), so their kind is short text: VARCHAR(191) COLLATE utf8mb4_bin on MySQL
// instead of the LONGTEXT they were made as. Migrate never alters an existing column, so this
// converts them in the working and the published table (Schema::convertColumn(), Preflow 0.75),
// before migrate's sync makes the indexes. A table or column that is missing (a fresh install:
// sync makes it typed) or already converted is skipped. A value longer than 191 characters
// stops the migration (LogicException, nothing changed): nothing is cut.
return new class extends Migration
{
    private const array TABLES = ['object', 'object__published'];

    private const array COLUMNS = ['state', 'category', 'owner'];

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            $types = $schema->columnTypes($table);
            foreach (self::COLUMNS as $column) {
                if (preg_match('/(text|blob)$/', strtolower($types[$column] ?? '')) === 1) {
                    $schema->convertColumn($table, new ColumnSpec($column, ColumnKind::Short));
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
    }
};
```

Notes for the controller:
- The migration file name is unchanged, so sites where it already ran (holz live) do not run it again. The rewrite matters for fresh installs and for code hygiene: it removes the "no workarounds" breach.
- On SQLite (a dev copy), Short is TEXT, so the `text` match makes each column a one-time rebuild that changes nothing. This is harmless, and the old version skipped SQLite entirely.
- If holz's `object.json` declares a `length` for these fields, pass it: `new ColumnSpec($column, ColumnKind::Short, <length>)`.
