<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('email')->index();
            $table->string('passwordHash')->exact();
            $table->text('roles')->exact()->nullable();
            $table->string('createdAt')->exact()->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('users');
    }
};
