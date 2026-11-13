<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\RecordTables;
use Preflow\Publishing\NoRecordColumns;
use Preflow\Publishing\RecordColumns;

/** Without Folio, `preflow migrate` makes the tables of a publishing type on SQL storage. */
final class PublishingTablesTest extends PublishingAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixtures = var_export(__DIR__ . '/Fixtures', true);
        $this->write('config/data.php', <<<PHP
            <?php
            return [
                'drivers' => [
                    'json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data'],
                    'sqlite' => ['path' => __DIR__ . '/../storage/app.sqlite'],
                ],
                'default' => 'json',
                'models_path' => __DIR__ . '/models',
                'model_paths' => [{$fixtures}],
            ];
            PHP);
        $this->write('config/models/article.json', (string) json_encode(['key' => 'article', 'table' => 'article', 'storage' => 'sqlite', 'publishing' => true, 'fields' => ['title' => ['type' => 'string'], 'views' => ['type' => 'number']]]));
        @mkdir($this->dir . '/storage', 0777, true);
    }

    public function test_migrate_creates_the_tables_and_a_second_run_changes_nothing(): void
    {
        $this->assertInstanceOf(NoRecordColumns::class, $this->app()->container()->get(RecordColumns::class));
        $first = $this->migrate();

        $schema = $this->schema();
        foreach (['article', 'article__published', 'article__revisions'] as $table) {
            $this->assertTrue($schema->hasTable($table), $table);
        }
        $this->assertNotSame([], $first);
        $this->assertArrayHasKey('_version', $schema->columnTypes('article'));
        $this->assertArrayHasKey('_published_by', $schema->columnTypes('article__published'));
        $this->assertSame('numeric', $schema->columnTypes('article__published')['views']);
        $this->assertSame([], $this->migrate(), 'a second migrate is a no-op');

        $listed = array_map(static fn (array $e): string => $e['def']->table, $this->app()->container()->get(RecordTables::class)->all());
        $this->assertContains('article__published', $listed);
        $this->assertSame(count($listed), count(array_unique($listed)), 'each table listed once');
    }

    public function test_publish_and_revisions_work_on_the_tables(): void
    {
        $this->migrate();
        $p = $this->publisher();
        $record = DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('article'), ['uuid' => 'a1', 'title' => 'One', 'views' => 3]);
        $p->saveDraft($record, null);
        $p->publish($this->stored('article', 'a1'), 'ana');
        $again = $this->stored('article', 'a1');
        $again->set('title', 'Two');
        $p->publish($again, 'ana');

        $this->assertSame('Two', $p->live('article', 'a1')?->get('title'));
        $this->assertSame([2, 1], array_map(static fn ($r): int => $r->seq, $p->revisions('article', 'a1')));
        $this->assertSame(['title'], $p->revisions('article', 'a1')[0]->changed);
    }

    /** @return list<string> */
    private function migrate(): array
    {
        $step = $this->app()->container()->get(MigrationRegistry::class)->steps()['publishing.tables'] ?? throw new \RuntimeException('step not registered');

        return $step();
    }

    private function schema(): Schema
    {
        $driver = $this->dm()->driver('sqlite');
        \assert($driver instanceof PdoDriver);

        return new Schema($driver->pdo());
    }
}
