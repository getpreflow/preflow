<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('jobs', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title')->nullable();
            $table->string('slug', 140)->nullable()->unique();
            $table->string('company')->nullable()->index();
            $table->string('place')->nullable();
            $table->string('employmentType')->nullable();
            $table->string('remote')->nullable();
            $table->integer('salaryFrom')->nullable();
            $table->integer('salaryTo')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->nullable();
            $table->string('publishedAt')->nullable();
            $table->string('createdAt')->nullable();
            $table->string('updatedAt')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('jobs');
    }
};
