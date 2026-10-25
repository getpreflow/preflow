<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Media;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Assets\AssetReferences;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Media\FolioRecordReferences;
use Preflow\Folio\Tests\Support\AssetFixtures;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;

/** A translatable asset field on SQL storage holds a JSON locale map; the asset in it counts as used. */
#[RequiresPhpExtension('gd')]
final class TranslatedAssetUsageTest extends TestCase
{
    use AssetFixtures;

    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        mkdir($this->assetRoot . '/models', 0777, true);
        file_put_contents($this->assetRoot . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'sql', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => [
                'title' => ['type' => 'string'],
                'hero' => ['type' => 'asset', 'translatable' => true],
                'gallery' => ['type' => 'asset', 'translatable' => true, 'asset' => ['multiple' => true]],
            ],
        ]));
        $this->registry = new TypeRegistry($this->assetRoot . '/models', new Locales(['en', 'de'], 'en'));
        $this->dm = new DataManager(drivers: ['sql' => new SqliteDriver(new \PDO('sqlite::memory:'))], defaultDriver: 'sql', typeRegistry: $this->registry);
        (new RecordTableSync($this->dm))->sync($this->registry->get('page'));
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_asset_in_a_translated_field_is_in_use_and_cannot_be_deleted(): void
    {
        $usage = new AssetUsage();
        $library = $this->library(usage: $usage);
        $usage->register(new FolioRecordReferences(
            new AssetReferences(new BlockRegistry([])),
            $this->dm,
            new TypeCatalog($this->assetRoot . '/models'),
            $this->registry,
            '/folio',
        ));
        $de = $library->upload($this->upload($this->png(10, 10), 'de.png'), null);
        $fr = $library->upload($this->upload($this->png(12, 12), 'fr.png'), null);
        $def = $this->registry->get('page');
        $this->dm->saveType(DynamicRecord::fromArray($def, [
            'uuid' => 'p1', 'title' => 'About', 'hero' => ['en' => $de->id], 'gallery' => ['de' => [$fr->id]],
        ]), false);

        $raw = $this->dm->driver('sql')->rawQuery('SELECT hero, gallery FROM page');
        $this->assertStringStartsWith('{', (string) $raw[0]['hero'], 'stored as a JSON locale map');
        $this->assertStringContainsString($de->id, (string) $raw[0]['hero']); // what the LIKE prefilter relies on

        foreach ([$de, $fr] as $asset) {
            try {
                $library->delete($asset->id);
                $this->fail('delete() should refuse an asset used in a translated field');
            } catch (AssetInUseException $e) {
                $this->assertCount(1, $e->usages);
                $this->assertSame('About', $e->usages[0]->label);
            }
            $this->assertNotNull($library->find($asset->id));
        }
        $spare = $library->upload($this->upload($this->png(14, 14), 'spare.png'), null);
        $library->delete($spare->id);
        $this->assertNull($library->find($spare->id));
    }
}
