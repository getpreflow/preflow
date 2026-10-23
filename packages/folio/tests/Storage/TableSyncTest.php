<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\Schema;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Storage\TableSync;

final class TableSyncTest extends TestCase
{
    private string $dir;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tsync_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->pdo = new \PDO('sqlite::memory:');
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

    public function test_creates_type_and_shadow_tables(): void
    {
        $this->model('post', ['publishing' => true, 'fields' => ['title' => ['type' => 'string'], 'body' => ['type' => 'text']]]);
        $this->model('memo', ['fields' => ['name' => ['type' => 'string']]]);

        $lines = $this->sync();

        $this->assertContains('created table post', $lines);
        $this->assertContains('created table post__published', $lines);
        $this->assertContains('created table post__revisions', $lines);
        $this->assertContains('created table memo', $lines);
        $this->assertNotContains('created table memo__published', $lines);
        $schema = new Schema($this->pdo);
        $this->assertFalse($schema->hasTable('memo__published'));
        $this->assertSame(
            ['uuid', 'title', 'body', '_locales', '_order', '_version', '_publish_at', '_share_token', '_published_at', '_published_by'],
            $schema->columns('post'),
        );
        $this->assertSame($schema->columns('post'), $schema->columns('post__published'));
        $this->assertSame(
            ['uuid', 'record_id', 'seq', 'data', 'changed', 'created_at', 'created_by', '_locales', '_order', '_version', '_publish_at', '_share_token', '_published_at', '_published_by'],
            $schema->columns('post__revisions'),
        );
        $info = $this->pdo->query('PRAGMA table_info("post")')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(['TEXT'], array_values(array_unique(array_column($info, 'type'))));
        $this->assertSame(['uuid'], array_column(array_filter($info, static fn ($c) => (int) $c['pk'] === 1), 'name'));
        $this->assertSame([], $this->sync(), 'a second run changes nothing');
    }

    public function test_adds_new_field_column_and_keeps_data(): void
    {
        $this->model('post', ['fields' => ['title' => ['type' => 'string']]]);
        $this->sync();
        $dm = $this->dm();
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('post'), ['uuid' => 'p1', 'title' => 'Kept']), false);

        $this->model('post', ['fields' => ['title' => ['type' => 'string'], 'subtitle' => ['type' => 'string']]]);

        $this->assertSame(['added column post.subtitle'], $this->sync());
        $row = $this->dm()->findType('post', 'p1');
        $this->assertSame('Kept', $row?->get('title'));
        $this->assertNull($row?->get('subtitle'));
    }

    public function test_never_drops_removed_field_column(): void
    {
        $this->model('post', ['fields' => ['title' => ['type' => 'string'], 'legacy' => ['type' => 'string']]]);
        $this->sync();
        $this->model('post', ['fields' => ['title' => ['type' => 'string']]]);

        $this->assertSame([], $this->sync());
        $this->assertContains('legacy', (new Schema($this->pdo))->columns('post'));
    }

    public function test_skips_json_stored_types(): void
    {
        $this->model('page', ['storage' => 'json', 'publishing' => true, 'fields' => ['title' => ['type' => 'string']]]);

        $this->assertSame([], $this->sync());
        $schema = new Schema($this->pdo);
        $this->assertFalse($schema->hasTable('page'));
        $this->assertFalse($schema->hasTable('page__published'));
    }

    public function test_unknown_column_names_migrate(): void
    {
        $this->model('post', ['fields' => ['title' => ['type' => 'string']]]);
        $this->sync();
        $this->model('post', ['fields' => ['title' => ['type' => 'string'], 'subtitle' => ['type' => 'string']]]);
        $dm = $this->dm();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Table post has no column subtitle; run `preflow migrate`.');
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('post'), ['uuid' => 'p1', 'title' => 'T', 'subtitle' => 'S']), false);
    }

    public function test_asset_table_when_its_storage_is_sql(): void
    {
        $assets = new \Preflow\Data\TypeDefinition('_asset', 'folio_assets', 'sqlite', ['path' => new \Preflow\Data\TypeFieldDefinition('path', 'string')]);

        $lines = $this->tableSync($assets)->sync();

        $this->assertSame(['created table folio_assets'], $lines);
        $this->assertContains('path', (new Schema($this->pdo))->columns('folio_assets'));
    }

    /** @return list<string> */
    private function sync(): array
    {
        return $this->tableSync()->sync();
    }

    private function tableSync(?\Preflow\Data\TypeDefinition $assets = null): TableSync
    {
        // Fresh catalog and registry each time: model files change between runs.
        $dm = $this->dm();

        return new TableSync(new TypeCatalog($this->dir . '/models'), $dm->getTypeRegistry(), $dm, $assets);
    }

    private function dm(): DataManager
    {
        return new DataManager(
            drivers: ['sqlite' => new SqliteDriver($this->pdo), 'json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'sqlite',
            typeRegistry: new TypeRegistry($this->dir . '/models'),
        );
    }

    /** @param array<string, mixed> $config */
    private function model(string $key, array $config): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", json_encode(
            $config + ['key' => $key, 'table' => $key, 'storage' => 'sqlite', 'id_field' => 'uuid'],
        ));
    }
}
