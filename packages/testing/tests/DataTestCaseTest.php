<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use PHPUnit\Framework\Attributes\Group;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Testing\DataTestCase;
use Preflow\Testing\MysqlTestDatabase;

#[Entity(table: 'notes', storage: 'sqlite')]
class TestNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $content = '';

    #[Field]
    public string $status = 'draft';
}

final class DataTestCaseTest extends DataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModelMetadata::clearCache();
        $this->createTable('notes', function ($table) {
            $table->uuid('uuid')->primary();
            $table->string('content');
            $table->string('status');
        });
    }

    public function test_sqlite_driver_available(): void
    {
        $this->assertNotNull($this->getSqliteDriver());
    }

    public function test_json_driver_available(): void
    {
        $this->assertNotNull($this->getJsonDriver());
    }

    public function test_save_and_find(): void
    {
        $note = new TestNote();
        $note->uuid = 'note-1';
        $note->content = 'Hello';
        $note->status = 'published';

        $this->dataManager()->save($note);

        $found = $this->dataManager()->find(TestNote::class, 'note-1');
        $this->assertSame('Hello', $found->content);
    }

    public function test_query(): void
    {
        $this->saveNote('1', 'First', 'published');
        $this->saveNote('2', 'Second', 'draft');

        $result = $this->dataManager()
            ->query(TestNote::class)
            ->where('status', 'published')
            ->get();

        $this->assertSame(1, $result->total());
    }

    private function saveNote(string $id, string $content, string $status): void
    {
        $note = new TestNote();
        $note->uuid = $id;
        $note->content = $content;
        $note->status = $status;
        $this->dataManager()->save($note);
    }

    #[Group('mysql')]
    public function test_use_mysql_points_default_and_mysql_at_an_emptied_database(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS leftover (id INT)');
        $this->assertInstanceOf(SqliteDriver::class, $this->dataManager()->driver('default'));

        $driver = $this->useMysql();

        $this->assertTrue($this->usesMysql());
        $this->assertSame($driver, $this->dataManager()->driver('default'));
        $this->assertSame($driver, $this->dataManager()->driver('mysql'));
        $this->assertInstanceOf(MysqlDriver::class, $driver);
        $this->assertInstanceOf(SqliteDriver::class, $this->dataManager()->driver('sqlite'));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn());
    }
}
