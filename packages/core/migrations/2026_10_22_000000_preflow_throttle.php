<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('preflow_throttle', function (Table $table) {
            $table->uuid('id')->primary();
            $table->string('purpose');
            $table->integer('hits');
            $table->integer('window_start');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('preflow_throttle');
    }
};
