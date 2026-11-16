<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

/** When a throttle row's window ends, so the row is deleted then (0.78). */
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->addColumns('preflow_throttle', function (Table $table) {
            $table->integer('expires_at')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropColumn('preflow_throttle', 'expires_at');
    }
};
