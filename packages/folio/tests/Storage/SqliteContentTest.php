<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Query;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\Collections;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\LiveContent;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\Singletons;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\RelationFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\VersionConflict;
use Preflow\Folio\Publishing\ShadowTypes;
use Preflow\Folio\Storage\TableSync;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;
use Preflow\Folio\Transfer\Importer;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/**
 * Folio's content features on a SQLite-stored type (tables from TableSync),
 * where the JSON driver's leniencies (decoded arrays, numeric-aware sort) don't apply.
 */
final class SqliteContentTest extends TestCase
{
    private string $dir;
    private DataManager $dm;
    private TypeCatalog $catalog;
    private Publisher $publisher;
    private LiveContent $live;
    private ContentLocale $locale;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sqlc_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->model('post', [
            'frontend' => ['route' => '/{slug}'],
            'list' => ['orderable' => true],
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
                'slug' => ['type' => 'string', 'translatable' => true],
                'related' => ['type' => 'relation', 'relation' => ['to' => 'post', 'multiple' => true]],
            ],
        ]);
        $this->model('site', ['singleton' => true, 'fields' => ['tagline' => ['type' => 'string', 'translatable' => true]]]);

        $this->catalog = new TypeCatalog($this->dir . '/models');
        $this->dm = $this->dataManager($this->dir . '/a.sqlite');
        $this->publisher = new Publisher($this->dm, $this->catalog, new RecordValidator($this->fieldTypes(), $this->dm));
        $this->live = new LiveContent($this->dm, $this->catalog);
        $this->locale = new ContentLocale(new Locales(['en', 'de'], 'en'));
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_collection_list_sorted_and_localized(): void
    {
        $this->publishPost('a', ['en' => 'Ten', 'de' => 'Zehn'], 10);
        $this->publishPost('b', ['en' => 'Two', 'de' => 'Zwei'], 2);
        $this->publishPost('c', ['en' => 'Thirty'], 30);

        $collections = new Collections($this->live, $this->catalog, $this->locale);
        $this->assertSame(['Two', 'Ten', 'Thirty'], array_column($collections->list('post'), 'title'));

        $this->locale->set('de');
        $collections = new Collections($this->live, $this->catalog, $this->locale);
        $this->assertSame(['Zwei', 'Zehn', 'Thirty'], array_column($collections->list('post'), 'title'));
        $this->assertSame('Zehn', $collections->find('post', 'a')['title'] ?? null);
    }

    public function test_publish_then_live_reads_published_copy(): void
    {
        $this->publishPost('p1', ['en' => 'Published'], 1);
        $edited = $this->dm->findType('post', 'p1') ?? throw new \RuntimeException('missing p1');
        $edited->set('title', ['en' => 'Draft edit']);
        $this->publisher->saveDraft($edited, (int) $edited->get('_version'));

        $this->assertSame(PublishState::Changed, $this->publisher->state($this->dm->findType('post', 'p1')));
        $this->assertSame('Published', $this->live->find('post', 'p1')?->localized('en')->toArray()['title']);
        $this->assertCount(1, $this->publisher->revisions('post', 'p1'));

        $this->publisher->publish($this->dm->findType('post', 'p1') ?? throw new \RuntimeException(), 'tester');

        $this->assertSame(PublishState::Published, $this->publisher->state($this->dm->findType('post', 'p1')));
        $this->assertSame(PublishState::Published, $this->publisher->states('post')['p1']);
        $this->assertSame('Draft edit', $this->live->find('post', 'p1')?->localized('en')->toArray()['title']);
        $this->assertSame('tester', $this->publisher->snapshot('post', 'p1')?->get('_published_by'));
        $this->assertSame([2, 1], array_map(static fn ($r) => $r->seq, $this->publisher->revisions('post', 'p1')));

        $this->publisher->unpublish('post', 'p1');
        $this->assertNull($this->live->find('post', 'p1'));
    }

    public function test_frontend_resolver_finds_by_localized_slug(): void
    {
        $this->publishPost('p1', ['en' => 'Hello', 'de' => 'Hallo'], 1, ['en' => 'hello', 'de' => 'hallo']);
        $resolver = FrontendResolver::fromCatalog($this->catalog, $this->dm);

        $this->assertSame('p1', $resolver->resolve('/hello', 'en')?->getId());
        $this->assertSame('p1', $resolver->resolve('/hallo', 'de')?->getId());
        $this->assertNull($resolver->resolve('/hallo', 'en'));
        $record = $resolver->resolve('/hello', 'en') ?? throw new \RuntimeException();
        $this->assertSame('/de/hallo', $resolver->urlFor($record, 'de'));
    }

    public function test_singleton_roundtrip(): void
    {
        $site = DynamicRecord::fromArray($this->dm->getTypeRegistry()->get('site'), ['uuid' => 'site', 'tagline' => ['en' => 'Live', 'de' => 'Echt'], '_locales' => ['en', 'de']]);
        $this->publisher->publish($site, null);
        $this->locale->set('de');

        $singletons = new Singletons($this->dm, $this->catalog, $this->locale, $this->live);

        $this->assertSame('Echt', $singletons->get('site')['tagline'] ?? null);
        $this->assertSame(['en', 'de'], $singletons->record('site')?->availableLocales());
    }

    public function test_array_field_roundtrips(): void
    {
        $this->publishPost('p1', ['en' => 'One'], 1, null, ['p2', 'p3']);
        $relation = new RelationFieldType($this->dm, $this->dm->getTypeRegistry());

        $this->assertSame(['p2', 'p3'], $relation->fromStorage($this->dm->findType('post', 'p1')?->get('related')));
        $this->assertSame(['p2', 'p3'], $relation->fromStorage($this->live->find('post', 'p1')?->get('related')));
        $this->assertSame(PublishState::Published, $this->publisher->state($this->dm->findType('post', 'p1')));
    }

    public function test_export_import_roundtrip_on_sqlite(): void
    {
        $this->publishPost('p1', ['en' => 'Hello', 'de' => 'Hallo'], 1, ['en' => 'hello'], ['p2']);
        $this->publishPost('p2', ['en' => 'Two'], 2);
        $edited = $this->dm->findType('post', 'p2') ?? throw new \RuntimeException();
        $edited->set('title', ['en' => 'Two edited']);
        $this->publisher->saveDraft($edited, null);

        $stream = fopen('php://temp', 'w+b');
        (new Exporter($this->contentSet($this->dm), $this->dm))->export($stream, 'data');
        rewind($stream);

        $target = $this->dataManager($this->dir . '/b.sqlite');
        $importer = new Importer($this->contentSet($target), $target);
        $plan = $importer->read($stream, 'data');
        try {
            $importer->apply($plan);
        } finally {
            $plan->cleanup();
        }

        foreach (array_keys($this->contentSet($this->dm)->tables()) as $table) {
            $this->assertSame($this->rows($this->dm, $table), $this->rows($target, $table), $table);
        }
        $this->assertSame([], array_filter($this->secondImportPlan($target), static fn (array $diff) => $diff !== ['added' => [], 'changed' => [], 'removed' => []]));
        $targetPublisher = new Publisher($target, $this->catalog, new RecordValidator($this->fieldTypes(), $target));
        $this->assertSame(PublishState::Changed, $targetPublisher->state($target->findType('post', 'p2') ?? throw new \RuntimeException()));
        $this->assertSame('Two', (new LiveContent($target, $this->catalog))->find('post', 'p2')?->localized('en')->toArray()['title']);
    }

    /** A re-export of the target imported into itself: nothing differs. */
    private function secondImportPlan(DataManager $target): array
    {
        $stream = fopen('php://temp', 'w+b');
        (new Exporter($this->contentSet($target), $target))->export($stream, 'data');
        rewind($stream);
        $plan = (new Importer($this->contentSet($target), $target))->read($stream, 'data');
        try {
            return $plan->tables;
        } finally {
            $plan->cleanup();
        }
    }

    /** Another connection saves after the editor loaded: the checked publish conflicts, nothing goes live. */
    public function test_checked_publish_refuses_a_save_from_another_connection(): void
    {
        $type = $this->dm->getTypeRegistry()->get('post');
        $this->publisher->saveDraft(DynamicRecord::fromArray($type, ['uuid' => 'p1', 'title' => ['en' => 'Start'], 'slug' => ['en' => 'p1']]), 0);
        $mine = $this->dm->findType('post', 'p1');

        $other = $this->dataManager($this->dir . '/a.sqlite');
        $theirs = $other->findType('post', 'p1');
        $theirs->set('title', ['en' => 'Theirs']);
        $this->assertTrue($other->saveTypeIfVersion($theirs, 1, validate: false));

        try {
            $this->publisher->publish($mine, 'me', 1);
            $this->fail('expected VersionConflict');
        } catch (VersionConflict $e) {
            $this->assertSame(2, $e->current);
        }
        $this->assertNull($this->publisher->snapshot('post', 'p1'));
        $this->assertSame('Theirs', json_decode((string) $this->dm->findType('post', 'p1')->get('title'), true)['en']);
    }

    /** The checked publish claims the working copy and writes the snapshot in one transaction. */
    public function test_checked_publish_rolls_back_the_working_copy_when_the_snapshot_fails(): void
    {
        $type = $this->dm->getTypeRegistry()->get('post');
        $this->dm->saveType(DynamicRecord::fromArray($type, ['uuid' => 'p1', 'title' => ['en' => 'Start'], 'slug' => ['en' => 'p1'], '_version' => 1, '_publish_at' => '2030-01-01T00:00:00+00:00']), false);
        $this->dm->driver('sqlite')->pdo()->exec('ALTER TABLE "post__published" DROP COLUMN "title"'); // the snapshot INSERT fails

        try {
            $this->publisher->publish($this->dm->findType('post', 'p1'), 'me', 1);
            $this->fail('the snapshot write should fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('post__published', $e->getMessage());
        }
        $this->assertSame('2030-01-01T00:00:00+00:00', $this->dm->findType('post', 'p1')->get('_publish_at'));
        $this->assertFalse($this->dm->driver('sqlite')->pdo()->inTransaction());
    }

    /**
     * @param array<string, string>      $title
     * @param array<string, string>|null $slug
     * @param list<string>|null          $related
     */
    private function publishPost(string $id, array $title, int $order, ?array $slug = null, ?array $related = null): void
    {
        $data = ['uuid' => $id, '_order' => $order, 'title' => $title, 'slug' => $slug ?? ['en' => $id], '_locales' => array_keys($title)];
        if ($related !== null) {
            $data['related'] = $related;
        }
        $this->publisher->publish(DynamicRecord::fromArray($this->dm->getTypeRegistry()->get('post'), $data), 'tester');
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(DataManager $dm, string $table): array
    {
        $def = $this->contentSet($dm)->tables()[$table];
        $rows = [];
        foreach ($dm->driver($def->storage)->findMany($table, new Query())->items() as $row) {
            $rows[(string) $row['uuid']] = $row;
        }
        ksort($rows);

        return $rows;
    }

    private function contentSet(DataManager $dm): ContentSet
    {
        $tables = [];
        foreach ($this->catalog->all() as $listing) {
            $def = $dm->getTypeRegistry()->get($listing->key);
            $tables[$def->table] = $def;
            if ($listing->publishing) {
                $tables[$def->table . '__published'] = ShadowTypes::published($def);
                $tables[$def->table . '__revisions'] = ShadowTypes::revisions($def);
            }
        }
        ksort($tables);

        return new ContentSet($tables, null, null);
    }

    private function dataManager(string $file): DataManager
    {
        $registry = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'));
        $dm = new DataManager(
            drivers: ['sqlite' => new SqliteDriver(new \PDO('sqlite:' . $file))],
            defaultDriver: 'sqlite',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        (new TableSync(new TypeCatalog($this->dir . '/models'), $registry, $dm))->sync();

        return $dm;
    }

    private function fieldTypes(): FieldTypeRegistry
    {
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());

        return $fieldTypes;
    }

    /** @param array<string, mixed> $config */
    private function model(string $key, array $config): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", json_encode(
            ['key' => $key, 'table' => $key, 'storage' => 'sqlite', 'id_field' => 'uuid'] + $config,
        ));
    }
}
