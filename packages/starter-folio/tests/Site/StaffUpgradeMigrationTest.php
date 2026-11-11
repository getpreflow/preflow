<?php

declare(strict_types=1);

namespace Tests\Site;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

/**
 * migrations/2026_11_11_005_upgrade_staff_columns.php brings a staff table of a project made before
 * 0.65 (camelCase columns) to rbac's column names; on a table made by the current create migration
 * it does nothing.
 */
final class StaffUpgradeMigrationTest extends TestCase
{
    private \PDO $pdo;
    private Schema $schema;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->schema = new Schema($this->pdo);
    }

    private function migration(string $file): Migration
    {
        return require dirname(__DIR__, 2) . '/migrations/' . $file;
    }

    public function test_an_old_staff_table_is_upgraded_and_keeps_its_accounts(): void
    {
        // The table as starter-folio created it before 0.65.
        $this->schema->create('staff', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('email')->nullable()->unique();
            $table->string('passwordHash')->nullable();
            $table->text('roles')->nullable();
            $table->string('name')->nullable();
            $table->string('createdAt')->nullable();
        });
        $this->pdo->exec("INSERT INTO staff (uuid, email, passwordHash, roles, name, createdAt) VALUES ('a', 'ada@example.com', 'hash', '[\"admin\"]', 'Ada', '2026-09-01 08:00:00')");

        $this->migration('2026_11_11_005_upgrade_staff_columns.php')->up($this->schema);

        $this->assertSame(['uuid', 'email', 'password_hash', 'roles', 'name', 'created_at', 'active', 'auth_epoch', 'last_login_at'], $this->schema->columns('staff'));
        $row = $this->pdo->query('SELECT * FROM staff')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('hash', $row['password_hash']);
        $this->assertSame('2026-09-01 08:00:00', $row['created_at']);
        $this->assertSame(1, (int) $row['active']);
        $this->assertSame('0', (string) $row['auth_epoch']);
        $this->assertNull($row['last_login_at']);

        // Running it again changes nothing.
        $this->migration('2026_11_11_005_upgrade_staff_columns.php')->up($this->schema);
        $this->assertSame($row, $this->pdo->query('SELECT * FROM staff')->fetch(\PDO::FETCH_ASSOC));
    }

    public function test_a_fresh_staff_table_is_left_as_it_is(): void
    {
        $this->migration('2026_11_09_004_create_staff.php')->up($this->schema);
        $this->pdo->exec("INSERT INTO staff (uuid, email, active, auth_epoch) VALUES ('b', 'bo@example.com', 0, '7')");
        $columns = $this->schema->columnTypes('staff');

        $this->migration('2026_11_11_005_upgrade_staff_columns.php')->up($this->schema);

        $this->assertSame($columns, $this->schema->columnTypes('staff'));
        $this->assertSame(['0', '7'], array_map('strval', $this->pdo->query('SELECT active, auth_epoch FROM staff')->fetch(\PDO::FETCH_NUM)), 'no backfill over existing values');
    }

    public function test_without_a_staff_table_it_does_nothing(): void
    {
        $this->migration('2026_11_11_005_upgrade_staff_columns.php')->up($this->schema);

        $this->assertFalse($this->schema->hasTable('staff'));
    }
}
