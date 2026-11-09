<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('staff', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('email')->nullable()->unique();
            $table->string('passwordHash')->nullable();
            $table->text('roles')->nullable();
            $table->string('name')->nullable();
            $table->string('createdAt')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('staff');
    }
};
