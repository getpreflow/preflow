<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

final class SchemaTest extends TestCase
{
    private \PDO $pdo;
    private Schema $schema;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->schema = new Schema($this->pdo);
    }

    public function test_create_table(): void
    {
        $this->schema->create('posts', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        // Verify table exists
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='posts'");
        $this->assertSame('posts', $stmt->fetchColumn());
    }

    public function test_table_has_columns(): void
    {
        $this->schema->create('posts', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title')->index();
            $table->integer('views')->nullable();
            $table->json('metadata')->nullable();
        });

        $stmt = $this->pdo->query("PRAGMA table_info(posts)");
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');

        $this->assertContains('uuid', $names);
        $this->assertContains('title', $names);
        $this->assertContains('views', $names);
        $this->assertContains('metadata', $names);
    }

    public function test_drop_table(): void
    {
        $this->pdo->exec('CREATE TABLE temp_table (id INTEGER)');

        $this->schema->drop('temp_table');

        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='temp_table'");
        $this->assertFalse($stmt->fetchColumn());
    }

    public function test_timestamps_creates_two_columns(): void
    {
        $this->schema->create('events', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->timestamps();
        });

        $stmt = $this->pdo->query("PRAGMA table_info(events)");
        $columns = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'name');

        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }
}
