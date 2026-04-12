<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('user_tokens', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('tokenHash')->index();
            $table->string('userId');
            $table->string('name')->nullable();
            $table->string('createdAt')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('user_tokens');
    }
};
