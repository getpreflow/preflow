<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

/**
 * Brings a staff table made before 0.65 (passwordHash, createdAt) to rbac's column names, as the
 * create migration makes it now: renames the two columns, adds active, auth_epoch and last_login_at,
 * and gives every existing account active = 1 and auth_epoch "0". On a table that has the new shape
 * already (a fresh install) it changes nothing; running it again is safe.
 *
 * A project made before 0.65: copy this file into your migrations/ and run `php preflow migrate`,
 * then take app/Models/Staff.php, app/Console/StaffCreateCommand.php and app/Seeds/DemoSeeder.php.
 */
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('staff')) {
            return;
        }
        $columns = $schema->columns('staff');
        foreach (['passwordHash' => 'password_hash', 'createdAt' => 'created_at'] as $old => $new) {
            if (in_array($old, $columns, true) && !in_array($new, $columns, true)) {
                $schema->renameColumn('staff', $old, $new);
            }
        }

        $missing = array_values(array_diff(['active', 'auth_epoch', 'last_login_at'], $schema->columns('staff')));
        if ($missing === []) {
            return;
        }
        $schema->addColumns('staff', function (Table $table) use ($missing): void {
            foreach ($missing as $column) {
                $column === 'active' ? $table->boolean($column) : $table->string($column);
            }
        });
        // Accounts that existed before: active, at epoch "0" (what a new account starts with).
        if (in_array('active', $missing, true)) {
            $schema->fillNulls('staff', 'active', true);
        }
        if (in_array('auth_epoch', $missing, true)) {
            $schema->fillNulls('staff', 'auth_epoch', '0');
        }
    }
};
