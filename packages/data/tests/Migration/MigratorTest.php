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

    public function test_unknown_driver_is_refused(): void
    {
        $pdo = new class ('sqlite::memory:') extends \PDO {
            public function getAttribute(int $attribute): mixed
            {
                return $attribute === \PDO::ATTR_DRIVER_NAME ? 'pgsql' : parent::getAttribute($attribute);
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No schema grammar for PDO driver "pgsql".');

        new Migrator($pdo, $this->migrationsDir);
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

    public function test_migrates_files_from_two_paths_in_name_order(): void
    {
        $second = sys_get_temp_dir() . '/preflow_migration_test_' . uniqid();
        mkdir($second, 0755, true);

        try {
            $this->createMigration(
                '2026_01_02_b.php',
                '$schema->create("b", function (Table $table) { $table->uuid("id")->primary(); });'
            );
            file_put_contents($second . '/2026_01_01_a.php', str_replace(
                '"b"',
                '"a"',
                file_get_contents($this->migrationsDir . '/2026_01_02_b.php')
            ));

            $migrator = new Migrator($this->pdo, [$this->migrationsDir, $second]);
            $this->assertSame(
                ['2026_01_01_a.php', '2026_01_02_b.php'],
                array_map('basename', $migrator->pending())
            );
            $migrator->migrate();

            $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('a','b') ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertSame(['a', 'b'], $tables);
        } finally {
            foreach (glob($second . '/*.php') as $file) {
                unlink($file);
            }
            rmdir($second);
        }
    }

    public function test_duplicate_migration_name_across_paths_throws(): void
    {
        $second = sys_get_temp_dir() . '/preflow_migration_test_' . uniqid();
        mkdir($second, 0755, true);

        try {
            $this->createMigration('2026_01_01_a.php', '// no-op');
            copy($this->migrationsDir . '/2026_01_01_a.php', $second . '/2026_01_01_a.php');

            $migrator = new Migrator($this->pdo, [$this->migrationsDir, $second]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('2026_01_01_a.php');
            $migrator->pending();
        } finally {
            unlink($second . '/2026_01_01_a.php');
            rmdir($second);
        }
    }
}
