<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title')->index();
            $table->string('slug')->index();
            $table->text('body')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('posts');
    }
};
