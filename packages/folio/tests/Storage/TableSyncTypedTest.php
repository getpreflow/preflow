<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Storage;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\RecordTables;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\MysqlTestDatabase;

/** Folio's tables: the working table typed with its unique index, __published typed without, __revisions text. */
final class TableSyncTypedTest extends TestCase
{
    use PublishingScaffold;

    protected function tearDown(): void
    {
        if (!MysqlTestDatabase::configured()) {
            $this->storage = 'json'; // a skipped MySQL test made no tables
        }
        $this->removeScaffold();
    }

    public function testRevisionsStayText(): void
    {
        $this->scaffoldEvents('sqlite');

        $this->assertContains('added unique index uniq_event_slug', $this->migrateStep());

        $schema = $this->schema();
        $this->assertSame('numeric', $schema->columnTypes('event')['seats']);
        $this->assertSame('integer', $schema->columnTypes('event')['open']);
        $this->assertContains('uniq_event_slug', $schema->indexes('event'));
        $this->assertSame('numeric', $schema->columnTypes('event__published')['seats']);
        $this->assertSame(['idx_event__published_slug'], self::own($schema->indexes('event__published')), 'the unique field plain indexed, never unique');
        $this->assertSame(['text'], array_values(array_unique($schema->columnTypes('event__revisions'))));
        $this->assertSame([], self::own($schema->indexes('event__revisions')));
        $this->assertSame([], $this->migrateStep());
    }

    #[Group('mysql')]
    public function testRevisionsStayTextOnMysql(): void
    {
        $this->scaffoldEvents('mysql');

        $this->assertContains('added unique index uniq_event_slug', $this->migrateStep());

        $schema = $this->schema();
        $this->assertSame('double', $schema->columnTypes('event')['seats']);
        $this->assertSame('varchar(191)', $schema->columnTypes('event')['slug']);
        $this->assertContains('uniq_event_slug', $schema->indexes('event'));
        $this->assertSame('double', $schema->columnTypes('event__published')['seats']);
        $this->assertEqualsCanonicalizing(['PRIMARY', 'idx_event__published_slug'], $schema->indexes('event__published'), 'the unique field plain indexed, never unique');
        $this->assertSame('varchar(191)', $schema->columnTypes('event__published')['slug']);
        $this->assertSame(['varchar(191)', 'longtext'], array_values(array_unique($schema->columnTypes('event__revisions'))), 'the uuid key, then text');
        $this->assertSame(['PRIMARY'], $schema->indexes('event__revisions'));
        $this->assertSame([], $this->migrateStep());
    }

    /** A form save and a publish write into the typed tables; an empty number is stored as NULL. */
    #[Group('mysql')]
    public function testPublishIntoTypedTablesOnMysql(): void
    {
        $this->scaffoldEvents('mysql');
        $this->migrateStep();

        $this->assertSame(302, $this->post('/folio/event', ['name' => 'Fair', 'slug' => 'fair', 'seats' => '', 'open' => '1', '_locale' => 'en', '_action' => 'publish'])->getStatusCode());

        $id = (string) $this->dm()->queryType('event')->first()?->getId();
        $this->assertNull($this->stored('event', $id)->get('seats'));
        $this->assertNotNull($this->publisher()->snapshot('event', $id));
    }

    public function testFolioRegistersEveryTableItSyncs(): void
    {
        $this->scaffoldEvents('sqlite');

        $tables = $this->app()->container()->get(RecordTables::class)->all();

        $byTable = [];
        foreach ($tables as $entry) {
            $byTable[$entry['def']->table] = $entry;
        }
        $this->assertTrue($byTable['event']['typed'] && $byTable['event']['unique']);
        $this->assertTrue($byTable['event__published']['typed']);
        $this->assertFalse($byTable['event__published']['unique']);
        $this->assertFalse($byTable['event__revisions']['typed']);
        $this->assertArrayHasKey('note__published', $byTable);
        $this->assertContains('_order', $byTable['event']['extra']);
    }

    private function scaffoldEvents(string $storage): void
    {
        $this->scaffoldPublishing($storage);
        $this->writeModel('event', [
            'label' => 'Events',
            'publishing' => true,
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'slug' => ['type' => 'string', 'unique' => true],
                'seats' => ['type' => 'number'],
                'open' => ['type' => 'boolean'],
                'starts' => ['type' => 'date'],
            ],
        ]);
    }

    /** @param list<string> $indexes @return list<string> without SQLite's own (primary key) indexes */
    private static function own(array $indexes): array
    {
        return array_values(array_filter($indexes, static fn (string $i): bool => !str_starts_with($i, 'sqlite_')));
    }

    private function schema(): Schema
    {
        $driver = $this->dm()->driver($this->storage);
        \assert($driver instanceof PdoDriver);

        return new Schema($driver->pdo());
    }

    /** @return list<string> */
    private function migrateStep(): array
    {
        return ($this->app()->container()->get(MigrationRegistry::class)->steps()['folio.tables'] ?? throw new \RuntimeException('step not registered'))();
    }
}
