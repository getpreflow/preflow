<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Media;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Assets\AssetReferences;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Media\FolioRecordReferences;
use Preflow\Folio\Publishing\ShadowTypes;
use Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock\HeroBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\TradeoffNoteBlock;
use Preflow\Media\Usage\AssetUsageEntry;

/**
 * Folio's usage scan on SQL storage: rows are prefiltered with LIKE on the
 * asset and blocks columns, types without such fields are not read at all,
 * and the result is what the full walk finds.
 */
class FolioRecordReferencesSqlTest extends TestCase
{
    protected const string ID = '0123456789abcdef0123456789abcdef';
    protected const string OTHER = 'fedcba9876543210fedcba9876543210';

    protected string $dir;
    protected DebugCollector $log;
    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_refs_sql_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->model('page', [
            'publishing' => true,
            'fields' => [
                'title' => ['type' => 'string'],
                'cover' => ['type' => 'asset'],
                'gallery' => ['type' => 'asset', 'asset' => ['multiple' => true]],
                'sections' => ['type' => 'blocks'],
            ],
        ]);
        $this->model('tag', ['fields' => ['title' => ['type' => 'string'], 'note' => ['type' => 'text']]]);
        $this->log = new DebugCollector();
        $this->registry = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'));
        $this->dm = new DataManager(drivers: ['sql' => $this->driver()], defaultDriver: 'sql', typeRegistry: $this->registry);
        $sync = new RecordTableSync($this->dm);
        foreach (['page', 'tag'] as $key) {
            $sync->sync($this->registry->get($key));
        }
        $sync->sync(ShadowTypes::published($this->registry->get('page')));
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir) || !is_dir($this->dir)) {
            return; // skipped before setUp ran (MySQL not configured)
        }
        foreach (glob($this->dir . '/models/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir . '/models');
        rmdir($this->dir);
    }

    protected function driver(): PdoDriver
    {
        return new SqliteDriver(new \PDO('sqlite::memory:'), $this->log);
    }

    public function test_finds_every_kind_of_reference_and_reads_only_candidate_rows(): void
    {
        $page = $this->registry->get('page');
        $this->save($page, 'p1', ['title' => 'Cover', 'cover' => self::ID]);
        $this->save($page, 'p3', ['title' => 'Gallery', 'gallery' => json_encode([self::OTHER, self::ID])]);
        $this->save($page, 'p4', ['title' => 'Blocks', 'sections' => [
            ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'hero', 'headline' => ['en' => 'Hi'], 'tradeoff' => [
                ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'tradeoff_note', 'chose' => ['en' => 'x'], 'cover' => self::ID],
            ]],
        ]]);
        $this->save($page, 'p5', ['title' => 'Other', 'cover' => self::OTHER]);
        // The id in a field the walker does not read (an unknown block): a LIKE candidate, not a usage.
        $this->save($page, 'p6', ['title' => 'Unknown block', 'sections' => [['_uid' => 'cccccccccccccccc', '_block' => 'gone', 'cover' => self::ID]]]);
        $this->save($page, 'p7', ['title' => 'Draft dropped it', 'cover' => '']);
        $this->save(ShadowTypes::published($page), 'p7', ['title' => 'Draft dropped it', 'cover' => self::ID]);
        $this->save($this->registry->get('tag'), 't1', ['title' => self::ID, 'note' => self::ID]);

        $usage = $this->references()->usagesOf(self::ID);

        $this->assertEquals([
            new AssetUsageEntry('Cover', '/folio/page/p1/edit', 'folio'),
            new AssetUsageEntry('Gallery', '/folio/page/p3/edit', 'folio'),
            new AssetUsageEntry('Blocks', '/folio/page/p4/edit', 'folio'),
            new AssetUsageEntry('Draft dropped it', '/folio/page/p7/edit', 'folio', 'published version'),
        ], $usage);
        $this->assertSame([], $this->references()->usagesOf(str_repeat('9', 32)));
    }

    public function test_types_without_asset_or_blocks_fields_are_not_queried_and_rows_are_prefiltered(): void
    {
        $page = $this->registry->get('page');
        $this->save($page, 'p1', ['title' => 'Cover', 'cover' => self::ID]);
        $this->save($this->registry->get('tag'), 't1', ['title' => self::ID]);
        $collector = new DebugCollector();
        $dm = new DataManager(drivers: ['sql' => $this->driverOn($collector)], defaultDriver: 'sql', typeRegistry: $this->registry);

        $usage = (new FolioRecordReferences($this->walker(), $dm, new TypeCatalog($this->dir . '/models'), $this->registry, '/folio'))->usagesOf(self::ID);

        $this->assertCount(1, $usage);
        $sql = array_map(static fn (array $q): string => (string) $q['sql'], $collector->getQueries());
        $this->assertNotSame([], $sql);
        foreach ($sql as $statement) {
            $this->assertStringNotContainsString('tag', $statement, 'a type without asset or blocks fields is not read');
            $this->assertStringContainsString(' LIKE ?', $statement, 'every read is prefiltered');
        }
        $this->assertCount(2, $sql); // the working table and its published shadow
        foreach ($collector->getQueries() as $q) {
            $this->assertContains('%' . self::ID . '%', $q['bindings']);
        }
    }

    /** The same connection, logging into $collector. */
    protected function driverOn(DebugCollector $collector): PdoDriver
    {
        $current = $this->dm->driver('sql');
        \assert($current instanceof PdoDriver);

        return new SqliteDriver($current->pdo(), $collector);
    }

    /** @param array<string, mixed> $data */
    private function save(\Preflow\Data\TypeDefinition $def, string $id, array $data): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($def, ['uuid' => $id] + $data), false);
    }

    /** @param array<string, mixed> $config */
    private function model(string $key, array $config): void
    {
        file_put_contents($this->dir . '/models/' . $key . '.json', json_encode(
            ['key' => $key, 'table' => $key, 'storage' => 'sql', 'id_field' => 'uuid', 'label' => ucfirst($key)] + $config,
        ));
    }

    private function walker(): AssetReferences
    {
        return new AssetReferences(new BlockRegistry([HeroBlock::class, LinkBlock::class, TradeoffNoteBlock::class]));
    }

    private function references(): FolioRecordReferences
    {
        return new FolioRecordReferences($this->walker(), $this->dm, new TypeCatalog($this->dir . '/models'), $this->registry, '/folio');
    }
}
