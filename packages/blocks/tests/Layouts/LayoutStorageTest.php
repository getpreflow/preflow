<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use PHPUnit\Framework\Attributes\DataProvider;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\Placement;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;

/**
 * "_layout" and "_areas" are saved and read with DataManager alone (no Folio) on JSON and SQLite,
 * validated by the layout rules; the stored shapes are also contract cases of every storage driver
 * (StorageDriverContract: MySQL and other drivers).
 */
final class LayoutStorageTest extends BlocksAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $sqlite = $this->dir . '/storage/pages.sqlite';
        @mkdir(dirname($sqlite), 0777, true);
        $pdo = new \PDO('sqlite:' . $sqlite);
        $pdo->exec('CREATE TABLE spage (uuid TEXT PRIMARY KEY, title TEXT, slug TEXT, _layout TEXT, _areas TEXT)');
        $pdo = null;
        $this->write('config/data.php', "<?php\nreturn ['drivers' => [\n"
            . "    'json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data'],\n"
            . "    'sqlite' => ['driver' => 'sqlite', 'path' => " . var_export($sqlite, true) . "],\n"
            . "], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Promo::class]];\n");
        foreach (['jpage' => 'json', 'spage' => 'sqlite'] as $key => $storage) {
            $this->write('config/models/' . $key . '.json', (string) json_encode([
                'key' => $key, 'table' => $key, 'storage' => $storage, 'id_field' => 'uuid',
                'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
                'layouts' => ['allowed' => ['promo', 'standard'], 'default' => 'standard'],
            ]));
        }
    }

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['JSON' => ['jpage'], 'SQLite' => ['spage']];
    }

    #[DataProvider('storages')]
    public function test_layout_and_areas_save_and_read_back(string $type): void
    {
        $areas = [
            'hero' => [['_uid' => 'b1', '_block' => 'banner', 'title' => ['en' => 'Welcome', 'de' => 'Willkommen']]],
            'main' => [],
            'side' => [['_uid' => 'q1', '_block' => 'quote', 'text' => ['en' => 'Grüße "quoted"']]],
        ];
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get($type), ['uuid' => 'p1', 'title' => 'About', '_layout' => 'promo', '_areas' => $areas]));

        $record = $this->data()->findType($type, 'p1');
        $this->assertSame('promo', $record?->get(PageLayouts::LAYOUT));
        $this->assertSame($areas, Placement::areasOf($record?->get(PageLayouts::AREAS)), 'the areas map, as saved (an array or JSON text of it)');

        $resolved = $this->app()->container()->get(PageLayouts::class)->resolve($record);
        $this->assertSame('promo', $resolved?->layout?->key);
        $this->assertSame(['hero', 'main'], array_keys($resolved?->placement->placed() ?? []));
        $this->assertSame(['side'], array_keys($resolved?->placement->unplaced() ?? []), 'an area the layout lacks is kept');

        // Saved again unchanged and switched: nothing is dropped.
        $record->set(PageLayouts::LAYOUT, 'standard');
        $this->data()->saveType($record);
        $again = $this->data()->findType($type, 'p1');
        $this->assertSame('standard', $again?->get(PageLayouts::LAYOUT));
        $this->assertSame($areas, Placement::areasOf($again?->get(PageLayouts::AREAS)));
    }

    #[DataProvider('storages')]
    public function test_the_rules_read_the_stored_layout_on_each_storage(string $type): void
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get($type), ['uuid' => 'p1', '_layout' => 'retired', '_areas' => '{}']), validate: false);

        $record = $this->data()->findType($type, 'p1');
        $record?->set('title', 'Still savable');
        $this->data()->saveType($record);
        $this->assertSame('retired', $this->data()->findType($type, 'p1')?->get(PageLayouts::LAYOUT), 'an unchanged stale key saves');
        $this->assertSame([], Placement::areasOf($this->data()->findType($type, 'p1')?->get(PageLayouts::AREAS)));
    }

    private function data(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    private function types(): TypeRegistry
    {
        return $this->app()->container()->get(TypeRegistry::class);
    }
}
