<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;
use Preflow\Fields\EmbedProviders;
use Preflow\Fields\FieldsServiceProvider;
use Preflow\Fields\Values\Embed;
use Preflow\Fields\Values\Location;
use Preflow\Fields\Values\Table;
use Preflow\Testing\MysqlTestDatabase;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * The embed, table and location fields on every storage (0.91): the stored shape read back as
 * saved through each value's own fromStored(), the checks on save without Folio, the columns, and
 * that loose search does not reach them.
 */
final class EmbedTableLocationStoredTest extends TestCase
{
    private string $dir;

    private bool $mysql = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fields_etl_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->mysql) {
            MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    #[DataProvider('storages')]
    public function test_every_type_round_trips(string $storage): void
    {
        $this->roundTrip($this->dm($storage));
    }

    #[Group('mysql')]
    public function test_every_type_round_trips_on_mysql(): void
    {
        $this->roundTrip($this->dm('mysql'));
    }

    private function roundTrip(DataManager $dm): void
    {
        $def = $dm->getTypeRegistry()->get('venue');
        $video = ['provider' => 'vimeo', 'id' => '76979871:8272103f6e', 'url' => 'https://vimeo.com/76979871/8272103f6e', 'poster' => 'a0f3'];
        $prices = ['head' => [['en' => 'Size', 'de' => 'Grösse'], 'CHF'], 'rows' => [['S', '10.–'], [['en' => 'Large "XL"', 'de' => 'Gross'], '']]];
        $place = ['lat' => 47.3769, 'lng' => -8.5417, 'address' => "Bahnhofplatz 1\n8001 Zürich"];
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'v1', 'name' => 'Full', 'video' => $video, 'prices' => $prices, 'place' => $place]));
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'v2', 'name' => 'Empty', 'video' => null, 'prices' => null, 'place' => null]));

        $full = $dm->findType('venue', 'v1');
        $this->assertSame($video, Embed::fromStored($full?->get('video'))?->toStored());
        $this->assertSame($prices, Table::fromStored($full?->get('prices'))?->toStored());
        $this->assertSame($place, Location::fromStored($full?->get('place'))?->toStored());

        $empty = $dm->findType('venue', 'v2');
        $this->assertNull(Embed::fromStored($empty?->get('video')));
        $this->assertNull(Table::fromStored($empty?->get('prices')));
        $this->assertNull(Location::fromStored($empty?->get('place')));
    }

    #[DataProvider('storages')]
    public function test_a_save_refuses_each_type_on_its_field_without_folio(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('venue');
        $save = static fn (array $values) => $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'bad', 'name' => 'x'] + $values));

        foreach ([
            ['video', 'https://www.dailymotion.com/video/x8abc', 'Videos from www.dailymotion.com cannot be embedded here; paste the address of a video on YouTube, Vimeo.'],
            ['video', 'https://video.example.org/w/kT2pQx', 'Videos from video.example.org cannot be embedded here; paste the address of a video on YouTube, Vimeo.'],
            ['prices', ['head' => null, 'rows' => [['1', '2', '3', '4']]], 'This table has 4 columns; at most 3 are allowed. Remove a column.'],
            ['prices', ['head' => null, 'rows' => [['1'], ['2'], ['3']]], 'This table has 3 rows; at most 2 are allowed. Remove a row.'],
            ['place', ['lat' => 95, 'lng' => 8, 'address' => null], 'The latitude must be a number from -90 to 90 (north is positive), such as 47.3769.'],
            ['place', ['lat' => 47, 'lng' => 200, 'address' => null], 'The longitude must be a number from -180 to 180 (east is positive), such as 8.5417.'],
        ] as [$field, $value, $message]) {
            try {
                $save([$field => $value]);
                $this->fail("saved {$field}");
            } catch (ValidationException $e) {
                $this->assertSame([$field => [$message]], $e->errors(), $field);
            }
        }
        $this->assertNull($dm->findType('venue', 'bad'));
    }

    #[DataProvider('storages')]
    public function test_a_field_that_lists_a_registered_provider_takes_its_addresses(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('venue');
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'name' => 'x', 'clip' => 'https://video.example.org/w/kT2pQx']));

        $this->assertSame('kT2pQx', Embed::fromStored($dm->findType('venue', 'p1')?->get('clip'), $this->providers())?->id);
    }

    public function test_columns(): void
    {
        $dm = $this->dm('json');
        $fields = $dm->getTypeRegistry()->get('venue')->fields;

        foreach (['video', 'prices', 'place'] as $name) {
            $this->assertSame(ColumnKind::Text, $dm->fieldKinds()->columnKindOf($fields[$name]), $name);
            $this->assertFalse($dm->fieldKinds()->isText('embed'), 'not searchable');
        }
        $this->assertFalse($dm->fieldKinds()->isText('table'));
        $this->assertFalse($dm->fieldKinds()->isText('location'));
    }

    #[DataProvider('storages')]
    public function test_loose_search_does_not_reach_the_structured_values(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('venue');
        $dm->saveType(DynamicRecord::fromArray($def, [
            'uuid' => 'v1', 'name' => 'Alpha',
            'prices' => ['head' => null, 'rows' => [['tablecellword']]],
            'place' => ['lat' => 47.1, 'lng' => 8.1, 'address' => 'addressword'],
        ]));
        $ids = static fn (string $term): array => array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $dm->queryType('venue')->searchLoose($term)->get()->items());

        $this->assertSame(['v1'], $ids('Alpha'));
        $this->assertSame([], $ids('tablecellword'));
        $this->assertSame([], $ids('addressword'));
    }

    private function providers(): EmbedProviders
    {
        $providers = new EmbedProviders();
        $providers->add(new Support\ExampleVideoProvider());

        return $providers;
    }

    private function dm(string $storage): DataManager
    {
        file_put_contents($this->dir . '/models/venue.json', (string) json_encode([
            'key' => 'venue', 'table' => 'venue', 'storage' => $storage, 'id_field' => 'uuid',
            'fields' => [
                'name' => ['type' => 'string'],
                'video' => ['type' => 'embed'],
                'clip' => ['type' => 'embed', 'providers' => ['example']],
                'prices' => ['type' => 'table', 'max_columns' => 3, 'max_rows' => 2],
                'place' => ['type' => 'location'],
            ],
        ]));
        $driver = match ($storage) {
            'json' => new JsonFileDriver($this->dir . '/data'),
            'sqlite' => new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/app.sqlite')),
            'mysql' => new MysqlDriver(MysqlTestDatabase::pdo()),
        };
        $this->mysql = $storage === 'mysql';
        $dm = new DataManager([$storage => $driver], $storage, new TypeRegistry($this->dir . '/models'), validatorFactory: new ValidatorFactory(new RuleFactory()));
        $providers = $this->providers();
        FieldsServiceProvider::registerKinds($dm->fieldKinds(), static fn (): EmbedProviders => $providers);
        if ($storage !== 'json') {
            (new RecordTableSync($dm))->sync($dm->getTypeRegistry()->get('venue'));
        }

        return $dm;
    }
}
