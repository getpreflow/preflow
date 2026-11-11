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

    /** The staff table as starter-folio created it before 0.65, with Ada (password "secret"). */
    private function oldTable(): void
    {
        $this->schema->create('staff', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('email')->nullable()->unique();
            $table->string('passwordHash')->nullable();
            $table->text('roles')->nullable();
            $table->string('name')->nullable();
            $table->string('createdAt')->nullable();
        });
        $stmt = $this->pdo->prepare("INSERT INTO staff (uuid, email, passwordHash, roles, name, createdAt) VALUES ('a', 'ada@example.com', ?, '[\"admin\"]', 'Ada', '2026-09-01 08:00:00')");
        $stmt->execute([password_hash('secret', PASSWORD_DEFAULT)]);
    }

    /** Every account can sign in as rbac's staff accounts do: its hash in password_hash verifies, it is active. */
    private function assertAccountsSignIn(): void
    {
        $this->assertNotContains('passwordHash', $this->schema->columns('staff'));
        $this->assertNotContains('createdAt', $this->schema->columns('staff'));
        foreach ($this->pdo->query('SELECT * FROM staff')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->assertTrue(password_verify('secret', (string) $row['password_hash']), (string) $row['email']);
            $this->assertSame(1, (int) $row['active']);
            $this->assertSame('0', (string) $row['auth_epoch']);
            $this->assertSame('2026-09-01 08:00:00', $row['created_at']);
        }
    }

    /** Folio's table sync ran first (rbac's model:staff enabled before the upgrade): the new columns sit next to the old, empty. */
    public function test_old_and_new_columns_side_by_side_are_merged(): void
    {
        $this->oldTable();
        foreach (['password_hash', 'active', 'auth_epoch', 'last_login_at', 'created_at'] as $column) {
            $this->schema->addColumn('staff', $column);
        }

        $this->migration('2026_11_11_005_upgrade_staff_columns.php')->up($this->schema);

        $this->assertAccountsSignIn();
    }

    /** A run that stopped halfway (passwordHash renamed, active added, no backfill yet) is finished by the next run. */
    public function test_a_rerun_after_a_partial_run_finishes_the_upgrade(): void
    {
        $this->oldTable();
        $this->schema->renameColumn('staff', 'passwordHash', 'password_hash');
        $this->schema->addColumns('staff', fn (Table $table) => $table->boolean('active'));

        $this->migration('2026_11_11_005_upgrade_staff_columns.php')->up($this->schema);

        $this->assertAccountsSignIn();
    }

    public function test_an_old_staff_table_is_upgraded_and_keeps_its_accounts(): void
    {
        $this->oldTable();

        $this->migration('2026_11_11_005_upgrade_staff_columns.php')->up($this->schema);

        $this->assertSame(['uuid', 'email', 'password_hash', 'roles', 'name', 'created_at', 'active', 'auth_epoch', 'last_login_at'], $this->schema->columns('staff'));
        $this->assertAccountsSignIn();
        $row = $this->pdo->query('SELECT * FROM staff')->fetch(\PDO::FETCH_ASSOC);
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
