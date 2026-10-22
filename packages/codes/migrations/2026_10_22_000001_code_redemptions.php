<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('code_redemption', function (Table $table) {
            $table->uuid('id')->primary();
            $table->uuid('code_id')->index();
            $table->string('purpose');
            $table->string('redeemer')->nullable();
            $table->integer('redeemed_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('code_redemption');
    }
};
