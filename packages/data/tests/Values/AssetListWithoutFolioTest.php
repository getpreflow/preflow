<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Values;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Describe\Description;
use Preflow\Data\Describe\DescriptionContributors;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Values\CoreValues;

/**
 * A media field with "asset.multiple" holds a list of ids in the core (0.93), as Folio has stored
 * it since 0.89: CoreValues registers it, so a site without Folio describes it as a list, stores a
 * list (a comma string or JSON text given becomes one) and reads one out, on SQL and JSON storage
 * alike. A single media field stays one id.
 */
final class AssetListWithoutFolioTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/asset_core_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        file_put_contents($this->dir . '/models/post.json', json_encode([
            'key' => 'post', 'table' => 'posts', 'storage' => 'default',
            'fields' => [
                'photo' => ['type' => 'asset'],
                'photos' => ['type' => 'asset', 'asset' => ['multiple' => true]],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_a_multiple_asset_field_is_a_list_without_folio(): void
    {
        $kinds = new FieldKinds();
        CoreValues::register($kinds);

        $this->assertTrue($kinds->holdsList(new TypeFieldDefinition('photos', 'asset', config: ['asset' => ['multiple' => true]])));
        $this->assertFalse($kinds->holdsList(new TypeFieldDefinition('photo', 'asset')));
    }

    public function test_the_description_says_list(): void
    {
        $types = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'));
        $dm = new DataManager(['default' => new JsonFileDriver($this->dir . '/store')], 'default', $types);

        $fields = (new Description($types, $dm->fieldKinds(), new DescriptionContributors()))->toArray()['types']['post']['fields'];

        $this->assertTrue($fields['photos']['list']);
        $this->assertSame('id_list', $fields['photos']['value'], 'the list flag and the output shape agree');
        $this->assertFalse($fields['photo']['list']);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'json' => ['json'];
    }

    #[DataProvider('storages')]
    public function test_a_multiple_asset_value_is_saved_and_read_back_as_a_list(string $storage): void
    {
        $types = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'));
        $driver = $storage === 'sqlite' ? new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/db.sqlite')) : new JsonFileDriver($this->dir . '/store');
        $dm = new DataManager(['default' => $driver], 'default', $types);
        $def = $types->get('post');
        if ($storage === 'sqlite') {
            (new RecordTableSync($dm))->sync($def);
        }

        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'photo' => 'i0', 'photos' => 'i1, i2']), false);

        $stored = $dm->findType('post', 'a')?->get('photos');
        $this->assertSame($storage === 'sqlite' ? '["i1","i2"]' : ['i1', 'i2'], $stored, 'a comma string is stored as a list');
        $this->assertSame('i0', $dm->findType('post', 'a')?->get('photo'));
        $field = $def->fields['photos'];
        $this->assertSame(['i1', 'i2'], $dm->fieldKinds()->valueOf($field)->toOutput($stored, $field));
    }
}
