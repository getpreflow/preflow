<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('auth_tokens', function (Table $table) {
            $table->string('id')->primary();
            $table->string('purpose');
            $table->string('subject');
            $table->integer('expires_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('auth_tokens');
    }
};
