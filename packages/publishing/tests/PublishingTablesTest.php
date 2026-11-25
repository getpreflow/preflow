<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Core\Boot\BootManifest;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\RecordTables;
use Preflow\Publishing\NoRecordColumns;
use Preflow\Publishing\PublishedIndexCheck;
use Preflow\Publishing\PublishingTypes;
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
        $this->assertArrayHasKey('_published_version', $schema->columnTypes('article__published'));
        $this->assertSame('numeric', $schema->columnTypes('article__published')['views']);
        $this->assertSame([], $this->migrate(), 'a second migrate is a no-op');

        $listed = array_map(static fn (array $e): string => $e['def']->table, $this->app()->container()->get(RecordTables::class)->all());
        $this->assertContains('article__published', $listed);
        $this->assertSame(count($listed), count(array_unique($listed)), 'each table listed once');
    }

    /** A type's declared indexes reach its existing tables and the published copies; the revisions get none. */
    public function test_declared_indexes_reach_the_published_table_but_not_the_revisions(): void
    {
        $this->migrate();
        $this->write('config/models/article.json', (string) json_encode([
            'key' => 'article', 'table' => 'article', 'storage' => 'sqlite', 'publishing' => true,
            'fields' => ['title' => ['type' => 'string', 'index' => true], 'views' => ['type' => 'number'], 'state' => ['type' => 'string']],
            'indexes' => [['state', 'views']],
        ]));
        $this->reboot();

        $lines = $this->migrate();

        foreach (['article', 'article__published'] as $table) {
            $this->assertContains("added index idx_{$table}_title", $lines);
            $this->assertContains("added index idx_{$table}_state_views", $lines);
            $this->assertEqualsCanonicalizing(["idx_{$table}_title", "idx_{$table}_state_views", "idx_{$table}__updated_at"], self::own($this->schema()->indexes($table)), $table);
        }
        $this->assertSame([], self::own($this->schema()->indexes('article__revisions')));
        $this->assertSame([], $this->migrate(), 'a second migrate is a no-op');
    }

    /** A PublishedIndex reaches the published table only, named like every composite; the working table and the revisions do not get it. */
    public function test_a_published_index_reaches_only_the_published_table(): void
    {
        $this->write('config/models/article.json', (string) json_encode([
            'key' => 'article', 'table' => 'article', 'storage' => 'sqlite', 'publishing' => true,
            'fields' => ['slug' => ['type' => 'string'], 'state' => ['type' => 'string']],
            'published_indexes' => [['slug', 'state']],
        ]));
        $this->reboot();

        $lines = $this->migrate();

        $this->assertContains('added index idx_article__published_slug_state', $lines);
        $this->assertNotContains('added index idx_article_slug_state', $lines);
        $this->assertEqualsCanonicalizing(['idx_article__published_slug_state', 'idx_article__published__updated_at'], self::own($this->schema()->indexes('article__published')));
        $this->assertSame(['idx_article__updated_at'], self::own($this->schema()->indexes('article')));
        $this->assertSame([], self::own($this->schema()->indexes('article__revisions')));
        $this->assertSame([], $this->migrate(), 'a second migrate is a no-op');
    }

    /** A published index on a type that does not publish: the boot is untouched, migrate refuses naming the attribute. */
    public function test_a_published_index_on_a_type_without_publishing_stops_migrate_not_the_boot(): void
    {
        $this->writeNote();
        $this->reboot();
        $this->app();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Type "note" declares PublishedIndex ("published_indexes"), but it does not publish');
        $this->migrate();
    }

    /** The manifest build refuses it too; a loaded manifest skips the scan. */
    public function test_the_manifest_build_refuses_it_and_a_loaded_manifest_skips_the_scan(): void
    {
        $this->writeNote();
        $this->reboot();
        $c = $this->app()->container();
        $dm = $c->get(DataManager::class);
        $types = $c->get(PublishingTypes::class);

        (new PublishedIndexCheck($dm, $types, BootManifest::off()))->assertWhenBuilding();
        (new PublishedIndexCheck($dm, $types, BootManifest::fromData(['catalogs' => []])))->assertWhenBuilding();
        try {
            (new PublishedIndexCheck($dm, $types, BootManifest::recording()))->assertWhenBuilding();
            $this->fail('the build accepted it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('declares PublishedIndex', $e->getMessage());
        }
    }

    /** The real provider wiring: building the manifest for the site (`preflow optimize`) refuses it. */
    public function test_building_the_manifest_through_the_provider_refuses_it(): void
    {
        $this->writeNote();

        try {
            (new \Preflow\Core\Boot\ManifestBuilder($this->dir))->build();
            $this->fail('the manifest build accepted it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('declares PublishedIndex', $e->getMessage());
        }
    }

    private function writeNote(): void
    {
        $this->write('config/models/note.json', (string) json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'sqlite',
            'fields' => ['slug' => ['type' => 'string']],
            'published_indexes' => [['slug']],
        ]));
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

    public function test_a_restore_clears_an_app_column_the_old_revision_lacks(): void
    {
        // An app's column besides the fields (Folio: the SEO section), bound before the tables are made.
        $this->app()->container()->singleton(RecordColumns::class, static fn (): RecordColumns => new class implements RecordColumns {
            public function columns(\Preflow\Data\TypeDefinition $def): array
            {
                return ['_seo', '_version'];
            }
        });
        $this->migrate();
        $p = $this->publisher();
        $p->saveDraft(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('article'), ['uuid' => 'a1', 'title' => 'One']), null);
        $p->publish($this->stored('article', 'a1'), 'ana');
        // A revision from before the column existed has no such key.
        $first = $p->revisions('article', 'a1')[0];
        $data = $first->data;
        unset($data['_seo']);
        $this->dm()->execute('UPDATE article__revisions SET data = ? WHERE uuid = ?', [json_encode($data), $first->id], 'sqlite');
        $again = $this->stored('article', 'a1');
        $again->set('title', 'Two');
        $again->set('_seo', '{"title":"Two"}');
        $p->publish($again, 'ana');
        $this->assertSame('{"title":"Two"}', $this->stored('article', 'a1')->get('_seo'));

        $p->restore('article', 'a1', $first->id);

        $this->assertSame('One', $this->stored('article', 'a1')->get('title'));
        $this->assertNull($this->stored('article', 'a1')->get('_seo'), 'cleared: SQL would keep the current value of a column left unnamed');
        $this->assertNotNull($this->stored('article', 'a1')->get('_version'), 'the system keys are kept');
    }

    /** @return list<string> */
    private function migrate(): array
    {
        $step = $this->app()->container()->get(MigrationRegistry::class)->steps()['publishing.tables'] ?? throw new \RuntimeException('step not registered');

        return $step();
    }

    /** @param list<string> $indexes @return list<string> without SQLite's own (primary key) indexes */
    private static function own(array $indexes): array
    {
        return array_values(array_filter($indexes, static fn (string $i): bool => !str_starts_with($i, 'sqlite_')));
    }

    private function schema(): Schema
    {
        $driver = $this->dm()->driver('sqlite');
        \assert($driver instanceof PdoDriver);

        return new Schema($driver->pdo());
    }
}
