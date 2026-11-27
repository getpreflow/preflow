<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

/**
 * Companies and jobs are data types (#[Entity(type: …)]), and every type's record keeps when it
 * last changed in the system column `_updated_at` (Preflow\Data\ChangeDates). A table a migration
 * made gets the column from a migration; `preflow migrate` then gives the rows already there a date.
 */
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        foreach (['companies', 'jobs'] as $table) {
            $schema->addColumns($table, function (Table $columns) {
                $columns->string('_updated_at')->nullable()->exact()->index();
            });
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['companies', 'jobs'] as $table) {
            $schema->dropColumn($table, '_updated_at');
        }
    }
};
