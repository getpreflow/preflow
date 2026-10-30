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

    public function test_autosave_refuses_a_save_landing_between_check_and_write(): void
    {
        $this->migrateStep();
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $body = (string) $this->racingRequest('/folio/post/p1/autosave', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '3'])->getBody();

        $this->assertStringContainsString('is-conflict', $body);
        $this->assertStringNotContainsString('hx-swap-oob', $body);
        $this->assertSame('Theirs', $this->storedTitle('p1'));
        $this->assertSame(4, (int) $this->stored('post', 'p1')->get('_version'));

        $this->post('/folio/post/p1/autosave', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '4']);
        $this->assertSame('Mine', $this->storedTitle('p1'));
        $this->assertSame(5, (int) $this->stored('post', 'p1')->get('_version'));
    }

    /** After a form publish (content unchanged: the version stays) the editor and autosave go on with the stored version. */
    public function test_form_and_autosave_after_a_form_publish(): void
    {
        $this->migrateStep();
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $this->assertSame(302, $this->post('/folio/post/p1', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '3', '_action' => 'publish'])->getStatusCode());
        $this->assertSame(4, (int) $this->stored('post', 'p1')->get('_version'), 'the draft save moved it on, the publish kept it');
        $this->assertStringContainsString('name="_version" id="folio-version" value="4"', (string) $this->get('/folio/post/p1/edit')->getBody());

        $auto = (string) $this->post('/folio/post/p1/autosave', ['title' => 'Later', 'slug' => 'hello', '_locale' => 'en', '_version' => '4'])->getBody();
        $this->assertStringContainsString('id="folio-version" value="5" hx-swap-oob="true"', $auto);
    }

    /** A publish that changed content (a site's approve) moves the version on: an editor still on the old one conflicts, a reload goes on. */
    public function test_form_and_autosave_after_a_publish_that_changed_content(): void
    {
        $this->migrateStep();
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], 'status' => 'review', '_version' => 3]);
        $approved = $this->stored('post', 'p1');
        $approved->set('status', 'approved');
        $this->publisher()->publish($approved, 'reviewer', 3);
        $this->assertSame(4, (int) $this->stored('post', 'p1')->get('_version'));

        $stale = (string) $this->post('/folio/post/p1/autosave', ['title' => 'Start', 'slug' => 'hello', 'status' => 'review', '_locale' => 'en', '_version' => '3'])->getBody();
        $this->assertStringContainsString('is-conflict', $stale);
        $this->assertSame(409, $this->post('/folio/post/p1', ['title' => 'Start', 'slug' => 'hello', 'status' => 'review', '_locale' => 'en', '_version' => '3'])->getStatusCode());
        $this->assertSame('approved', $this->stored('post', 'p1')->get('status'));

        $this->assertStringContainsString('name="_version" id="folio-version" value="4"', (string) $this->get('/folio/post/p1/edit')->getBody());
        $auto = (string) $this->post('/folio/post/p1/autosave', ['title' => 'Later', 'slug' => 'hello', 'status' => 'approved', '_locale' => 'en', '_version' => '4'])->getBody();
        $this->assertStringContainsString('id="folio-version" value="5" hx-swap-oob="true"', $auto);
    }

    /** A save landing between the form's draft save and its publish: the publish conflicts, nothing goes live. */
    public function test_form_publish_refuses_a_save_landing_before_the_publish(): void
    {
        $this->migrateStep();
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $res = $this->racingRequest('/folio/post/p1', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '3', '_action' => 'publish'], skip: 1, theirVersion: 5);

        $this->assertSame(409, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Someone else changed this record', $body);
        $this->assertStringContainsString('name="_version" id="folio-version" value="5"', $body);
        $this->assertSame('Theirs', $this->storedTitle('p1'));
        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
    }

    /** Another connection saves after the editor loaded: the checked publish (in its transaction) conflicts, nothing goes live. */
    public function test_checked_publish_refuses_a_save_from_another_connection(): void
    {
        $this->migrateStep();
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);
        $mine = $this->stored('post', 'p1');

        $c = MysqlTestDatabase::dataConfig();
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            \Preflow\Data\Driver\MysqlDriver::connectionOptions(),
        );
        $other = new DataManager(['mysql' => new \Preflow\Data\Driver\MysqlDriver($pdo)], 'mysql', $this->dm()->getTypeRegistry());
        $theirs = $other->findType('post', 'p1');
        $theirs->set('title', ['en' => 'Theirs']);
        $this->assertTrue($other->saveTypeIfVersion($theirs, 3, validate: false));

        try {
            $this->publisher()->publish($mine, 'me', 3);
            $this->fail('expected VersionConflict');
        } catch (\Preflow\Folio\Publishing\VersionConflict $e) {
            $this->assertSame(4, $e->current);
        }
        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
        $this->assertSame('Theirs', $this->storedTitle('p1'));

        $this->publisher()->publish($this->stored('post', 'p1'), 'me', 4);
        $this->assertNotNull($this->publisher()->snapshot('post', 'p1'));
        $this->assertSame(4, (int) $this->stored('post', 'p1')->get('_version'));
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

    public function test_translation_sources_and_machine_marks_persist_on_mysql(): void
    {
        $this->migrateStep();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $res = $this->post('/folio/post/p1/autosave', ['title' => 'Hallo', 'slug' => 'hallo', '_locale' => 'de', '_version' => '0', '_ai_unchecked' => ['title']]);

        $this->assertSame(200, $res->getStatusCode());
        $status = $this->app()->container()->get(\Preflow\Folio\Content\TranslationStatus::class);
        $stored = $this->stored('post', 'p1');
        $this->assertSame('Hello', $status->previousSource($stored, 'de', 'title'));
        $unchecked = $status->unchecked($this->dm()->getTypeRegistry()->get('post'), $stored, 'de');
        $this->assertSame(['title'], array_map(static fn ($t) => $t->path, $unchecked));
    }

    /**
     * A site upgraded to 0.47 has tables without the translation-source and machine-mark columns
     * until `preflow migrate` adds them; after that, publishing and re-saving a translation work.
     */
    public function test_tables_from_before_0_47_take_translations_after_migrate(): void
    {
        $this->migrateStep();
        $pdo = MysqlTestDatabase::pdo();
        foreach (['post', 'post__published'] as $table) {
            $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `_translated_source`, DROP COLUMN `_machine_translated`");
        }

        $lines = $this->migrateStep();

        $this->assertNotSame([], $lines, 'migrate adds the missing columns');
        $this->assertSame(302, $this->post('/folio/post', ['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en', '_locales_submitted' => '1', '_locales' => ['de'], '_action' => 'publish'])->getStatusCode());
        $id = (string) $this->dm()->queryType('post')->first()?->getId();
        $this->assertSame(302, $this->post('/folio/post/' . $id, ['title' => 'Hallo', 'slug' => 'hallo', '_locale' => 'de', '_version' => (string) $this->stored('post', $id)->get('_version'), '_action' => 'publish'])->getStatusCode());
        $this->assertSame(302, $this->post('/folio/post/' . $id, ['title' => 'Hallo du', 'slug' => 'hallo', '_locale' => 'de', '_version' => (string) $this->stored('post', $id)->get('_version'), '_ai_unchecked' => ['title'], '_action' => 'publish'])->getStatusCode());

        $this->assertStringContainsString('<h1>Hallo du</h1>', (string) $this->get('/de/hallo')->getBody());
        $status = $this->app()->container()->get(\Preflow\Folio\Content\TranslationStatus::class);
        $this->assertSame('Hello', $status->previousSource($this->stored('post', $id), 'de', 'title'));
        $this->assertCount(1, $status->unchecked($this->dm()->getTypeRegistry()->get('post'), $this->stored('post', $id), 'de'));
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

    public function test_record_ids_differing_in_case_or_accent_coexist(): void
    {
        $this->migrateStep();
        foreach (['A', 'a', 'ä'] as $id) {
            $this->working('post', $id, ['title' => ['en' => "T {$id}"], 'slug' => ['en' => "s-{$id}"]]);
        }

        $this->assertSame(3, (int) MysqlTestDatabase::pdo()->query('SELECT COUNT(*) FROM `post`')->fetchColumn());
        foreach (['A', 'a', 'ä'] as $id) {
            $this->assertSame($id, $this->dm()->findType('post', $id)?->getId());
            $this->assertSame("T {$id}", json_decode((string) $this->stored('post', $id)->get('title'), true)['en'] ?? null, $id);
        }
    }

    public function test_revisions_of_ids_differing_in_case_stay_apart(): void
    {
        $this->assertRevisionsStayApart('A', 'a');
    }

    public function test_revisions_of_ids_differing_in_accent_stay_apart(): void
    {
        $this->assertRevisionsStayApart('a', 'ä');
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
    private function assertRevisionsStayApart(string $first, string $second): void
    {
        $this->migrateStep();
        $a = $this->working('post', $first, ['title' => ['en' => "T {$first}"], 'slug' => ['en' => "s-{$first}"]]);
        $b = $this->working('post', $second, ['title' => ['en' => "T {$second}"], 'slug' => ['en' => "s-{$second}"]]);

        // The twin gets 3 publishes, the first record 22 (past the 20 kept).
        for ($i = 1; $i <= 3; $i++) {
            $this->publisher()->publish($b, null);
        }
        for ($i = 1; $i <= 22; $i++) {
            $this->publisher()->publish($a, null);
        }

        $mine = $this->publisher()->revisions('post', $first);
        $theirs = $this->publisher()->revisions('post', $second);
        $this->assertCount(20, $mine, $first);
        $this->assertCount(3, $theirs, $second);
        $this->assertSame([3, 2, 1], array_map(static fn ($r) => $r->seq, $theirs), $second);
        $this->assertSame(22, $mine[0]->seq, $first);
        $this->assertSame("T {$second}", $theirs[0]->data['title']['en'] ?? null);
        $this->assertSame("T {$first}", $mine[0]->data['title']['en'] ?? null);
    }

    /** The English title as stored (SQL columns hold a translatable field as JSON text). */
    private function storedTitle(string $id): ?string
    {
        $title = $this->stored('post', $id)->get('title');

        return (is_string($title) ? json_decode($title, true) : $title)['en'] ?? null;
    }

    private function migrateStep(): array
    {
        return ($this->app()->container()->get(MigrationRegistry::class)->steps()['folio.tables'] ?? throw new \RuntimeException('step not registered'))();
    }
}
