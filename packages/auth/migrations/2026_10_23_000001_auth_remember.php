<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('auth_remember', function (Table $table) {
            $table->string('id')->primary();
            $table->string('guard')->exact();
            $table->string('user_id')->exact();
            $table->string('validator_hash')->exact();
            $table->string('previous_hash')->exact()->nullable();
            $table->integer('rotated_at')->nullable();
            $table->integer('expires_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('auth_remember');
    }
};
