<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** A type that declares full-text search on a server without full-text indexes: synced as any other. */
final class RecordTableSyncFullTextTest extends TestCase
{
    public function test_a_flagged_type_syncs_on_sqlite_without_a_full_text_index(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $sync = new RecordTableSync(new DataManager(['sqlite' => new SqliteDriver($pdo)]));
        $def = new TypeDefinition('post', 'post', 'sqlite', [
            'title' => new TypeFieldDefinition('title'),
            'body' => new TypeFieldDefinition('body', 'text'),
        ], fullText: true);

        $this->assertSame(['created table post'], $sync->sync($def));
        $this->assertSame([], $sync->sync($def));
    }
}
