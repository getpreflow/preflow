<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Testing\DataTestCase;

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
}
