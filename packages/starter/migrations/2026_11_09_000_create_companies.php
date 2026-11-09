<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('companies', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('name')->nullable();
            $table->string('slug', 100)->nullable()->unique();
            $table->string('place')->nullable();
            $table->string('website')->nullable();
            $table->string('summary')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('companies');
    }
};
