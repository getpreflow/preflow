<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\SortDirection;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;
use Preflow\Folio\Transfer\Importer;
use Preflow\Testing\MysqlTestDatabase;

/** The publishing scaffold with MySQL-stored types, tables made by the `folio.tables` migrate step. */
#[Group('mysql')]
final class MysqlAppTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        $this->scaffoldPublishing('mysql');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_migrate_step_creates_the_tables(): void
    {
        $lines = $this->migrateStep();

        $this->assertContains('created table post', $lines);
        $this->assertContains('created table post__published', $lines);
        $this->assertContains('created table site__revisions', $lines);
        $this->assertSame([], $this->migrateStep());
    }

    public function test_admin_publish_and_public_page(): void
    {
        $this->migrateStep();

        $this->assertSame(302, $this->post('/folio/post', ['title' => 'Fresh', 'slug' => 'hello', '_locale' => 'en', '_action' => 'publish'])->getStatusCode());

        $this->assertStringContainsString('<h1>Fresh</h1>', (string) $this->get('/hello')->getBody());
    }

    public function test_saving_another_locale_keeps_visible_locales(): void
    {
        $this->migrateStep();
        $this->post('/folio/post', ['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en', '_locales_submitted' => '1', '_locales' => ['de'], '_action' => 'publish']);
        $id = (string) $this->dm()->queryType('post')->first()?->getId();

        $this->post('/folio/post/' . $id, ['title' => 'Hallo', 'slug' => 'hallo', '_locale' => 'de', '_action' => 'publish']);

        $this->assertSame(['en', 'de'], $this->stored('post', $id)->availableLocales());
        $this->assertStringContainsString('<h1>Hallo</h1>', (string) $this->get('/de/hallo')->getBody());
    }

    public function test_reorder_swaps_positions_without_renumbering(): void
    {
        $this->migrateStep();
        $this->publisher()->publish($this->working('post', 'a', ['title' => ['en' => 'A'], 'slug' => ['en' => 'a'], '_order' => 5]), null);
        $this->publisher()->publish($this->working('post', 'b', ['title' => ['en' => 'B'], 'slug' => ['en' => 'b'], '_order' => 7]), null);

        $this->assertSame(302, $this->post('/folio/post/b/move', ['direction' => 'up'])->getStatusCode());

        $this->assertSame('5', $this->publisher()->snapshot('post', 'b')?->get('_order'));
        $this->assertSame('7', $this->publisher()->snapshot('post', 'a')?->get('_order'));
    }

    public function test_folio_table_columns_on_mysql(): void
    {
        $this->migrateStep();

        $stmt = MysqlTestDatabase::pdo()->query(
            "SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post'"
        );
        $columns = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $columns[$row['COLUMN_NAME']] = $row;
        }

        $this->assertSame('varchar(191)', strtolower($columns['uuid']['COLUMN_TYPE']));
        $this->assertSame('PRI', $columns['uuid']['COLUMN_KEY']);
        foreach (['title', 'sections'] as $name) {
            $this->assertSame('longtext', strtolower($columns[$name]['COLUMN_TYPE']), $name);
            $this->assertSame('YES', $columns[$name]['IS_NULLABLE'], $name);
        }
    }

    public function test_large_text_field_round_trip(): void
    {
        $this->migrateStep();
        $body = str_repeat('ä', 100000);
        $this->publisher()->publish($this->working('post', 'big', ['title' => ['en' => 'Big'], 'slug' => ['en' => 'big'], 'body' => ['en' => $body]]), null);

        $stored = $this->dm()->findType('post', 'big');

        // SQL rows hold translatable values as a JSON string of {locale: text}.
        $this->assertSame($body, json_decode((string) $stored?->get('body'), true)['en'] ?? null);
        $this->assertSame($body, json_decode((string) $this->publisher()->snapshot('post', 'big')?->get('body'), true)['en'] ?? null);
    }

    public function test_localized_query_and_numeric_sort(): void
    {
        $this->migrateStep();
        foreach ([['a', 2, 'Zwei', 'Two'], ['b', 10, 'Hallo', 'Ten'], ['c', 1, 'Eins', 'One']] as [$id, $order, $de, $en]) {
            $this->working('post', $id, ['title' => ['en' => $en, 'de' => $de], 'slug' => ['en' => $id], '_order' => $order]);
        }

        $ordered = $this->dm()->queryType('post')->orderBy('_order', SortDirection::Asc, true)->get();
        $this->assertSame(['c', 'a', 'b'], array_map(static fn ($r) => $r->getId(), $ordered->items()));

        $found = $this->dm()->queryType('post')->locale('de')->where('title', 'Hallo')->get();
        $this->assertSame(['b'], array_map(static fn ($r) => $r->getId(), $found->items()));
    }

    public function test_export_import_round_trip(): void
    {
        $this->migrateStep();
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]), 'tester');
        $this->assertStringContainsString('<h1>Hello</h1>', (string) $this->get('/hello')->getBody());

        $app = $this->app();
        $stream = fopen('php://temp', 'w+b');
        (new Exporter(ContentSet::fromApp($app), $app->container()->get(DataManager::class)))->export($stream, 'all');
        rewind($stream);

        MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        $this->migrateStep();

        $app = $this->app();
        $importer = new Importer(ContentSet::fromApp($app), $app->container()->get(DataManager::class));
        $plan = $importer->read($stream, 'all');
        try {
            $importer->apply($plan);
        } finally {
            $plan->cleanup();
        }

        $this->assertStringContainsString('<h1>Hello</h1>', (string) $this->get('/hello')->getBody());
    }

    public function test_table_sync_adds_a_new_field_column_on_mysql(): void
    {
        $this->migrateStep();
        $model = json_decode((string) file_get_contents($this->dir . '/config/models/post.json'), true);
        $model['fields']['subtitle'] = ['type' => 'string'];
        unset($model['key'], $model['table'], $model['storage'], $model['id_field']);
        $this->writeModel('post', $model);

        $this->assertContains('added column post.subtitle', $this->migrateStep());

        $column = MysqlTestDatabase::pdo()->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post' AND COLUMN_NAME = 'subtitle'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['COLUMN_TYPE' => 'longtext', 'IS_NULLABLE' => 'YES'], $column === false ? null : ['COLUMN_TYPE' => strtolower($column['COLUMN_TYPE']), 'IS_NULLABLE' => $column['IS_NULLABLE']]);

        $this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 't'], 'subtitle' => 'Sub']);
        $this->assertSame('Sub', $this->stored('post', 'p1')->get('subtitle'));
    }

    /** @return list<string> */
    private function migrateStep(): array
    {
        return ($this->app()->container()->get(MigrationRegistry::class)->steps()['folio.tables'] ?? throw new \RuntimeException('step not registered'))();
    }
}
