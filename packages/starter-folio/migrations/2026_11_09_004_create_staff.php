<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

/**
 * Staff accounts (App\Models\Staff). The columns are those of preflow/rbac's staff type, so a site
 * that switches to Folio's role editor (the rbac extension with its "model:staff" part) keeps this
 * table and its accounts: see "Roles edited in Folio" in the README.
 */
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('staff', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('email')->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('password_hash')->nullable();
            $table->text('roles')->nullable();
            $table->boolean('active')->nullable();
            $table->string('auth_epoch')->nullable();
            $table->string('last_login_at')->nullable();
            $table->string('created_at')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('staff');
    }
};
