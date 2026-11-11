<?php

use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

/**
 * Brings a staff table made before 0.65 (passwordHash, createdAt) to rbac's column names, as the
 * create migration makes it now, from whatever shape it is in: an old column is renamed, or, when
 * its new column exists already (Folio's table sync added it, or a run stopped halfway), merged into
 * it (old values fill the rows where the new one is empty) and dropped; active, auth_epoch and
 * last_login_at are added when missing; every account without active / auth_epoch gets 1 / "0"
 * (what a new account starts with). On a table of the new shape (a fresh install) it changes
 * nothing; running it again is safe.
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
        foreach (['passwordHash' => 'password_hash', 'createdAt' => 'created_at'] as $old => $new) {
            $columns = $schema->columns('staff');
            if (!in_array($old, $columns, true)) {
                continue;
            }
            if (in_array($new, $columns, true)) {
                $schema->copyColumn('staff', $old, $new);
                $schema->dropColumn('staff', $old);
            } else {
                $schema->renameColumn('staff', $old, $new);
            }
        }

        $missing = array_values(array_diff(['active', 'auth_epoch', 'last_login_at'], $schema->columns('staff')));
        if ($missing !== []) {
            $schema->addColumns('staff', function (Table $table) use ($missing): void {
                foreach ($missing as $column) {
                    $column === 'active' ? $table->boolean($column) : $table->string($column);
                }
            });
        }
        $schema->fillNulls('staff', 'active', true);
        $schema->fillNulls('staff', 'auth_epoch', '0');
    }
};
