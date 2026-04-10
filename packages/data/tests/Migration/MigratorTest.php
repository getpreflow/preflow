<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Migrator;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

final class MigratorTest extends TestCase
{
    private \PDO $pdo;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->migrationsDir = sys_get_temp_dir() . '/preflow_migration_test_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsDir . '/*.php') as $file) {
            unlink($file);
        }
        rmdir($this->migrationsDir);
    }

    private function createMigration(string $filename, string $upSql, string $downSql = ''): void
    {
        $content = <<<PHP
        <?php
        use Preflow\Data\Migration\Migration;
        use Preflow\Data\Migration\Schema;
        use Preflow\Data\Migration\Table;

        return new class extends Migration {
            public function up(Schema \$schema): void
            {
                {$upSql}
            }
            public function down(Schema \$schema): void
            {
                {$downSql}
            }
        };
        PHP;

        file_put_contents($this->migrationsDir . '/' . $filename, $content);
    }

    public function test_runs_pending_migrations(): void
    {
        $this->createMigration(
            '2026_01_01_create_users.php',
            '$schema->create("users", function (Table $table) { $table->uuid("uuid")->primary(); $table->string("name"); });',
            '$schema->drop("users");'
        );

        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        $this->assertSame('users', $stmt->fetchColumn());
    }

    public function test_tracks_run_migrations(): void
    {
        $this->createMigration(
            '2026_01_01_create_users.php',
            '$schema->create("users", function (Table $table) { $table->uuid("uuid")->primary(); });'
        );

        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        $pending = $migrator->pending();
        $this->assertCount(0, $pending);
    }

    public function test_does_not_rerun_migrations(): void
    {
        $this->createMigration(
            '2026_01_01_create_users.php',
            '$schema->create("users", function (Table $table) { $table->uuid("uuid")->primary(); });'
        );

        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();
        $migrator->migrate(); // second run should be no-op

        // If it tried to create again, it would throw
        $this->assertTrue(true);
    }

    public function test_runs_migrations_in_order(): void
    {
        $this->createMigration(
            '2026_01_02_create_posts.php',
            '$schema->create("posts", function (Table $table) { $table->uuid("uuid")->primary(); });'
        );
        $this->createMigration(
            '2026_01_01_create_users.php',
            '$schema->create("users", function (Table $table) { $table->uuid("uuid")->primary(); });'
        );

        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        // Both tables should exist
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('users','posts') ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['posts', 'users'], $tables);
    }

    public function test_pending_returns_unrun_migrations(): void
    {
        $this->createMigration('2026_01_01_a.php', '// no-op');
        $this->createMigration('2026_01_02_b.php', '// no-op');

        $migrator = new Migrator($this->pdo, $this->migrationsDir);

        $pending = $migrator->pending();
        $this->assertCount(2, $pending);
    }
}
