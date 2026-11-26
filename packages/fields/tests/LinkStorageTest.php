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
use Preflow\Fields\FieldsServiceProvider;
use Preflow\Fields\Values\Link;
use Preflow\Testing\MysqlTestDatabase;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * A link field on every storage (0.91): its column is text (FieldKinds, registered by
 * FieldsServiceProvider::registerKinds()), each kind is read back as the same Link through
 * fromStored(), and a save checks it (ValidLink) without Folio.
 */
final class LinkStorageTest extends TestCase
{
    private string $dir;

    private bool $mysql = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fields_link_' . bin2hex(random_bytes(4));
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
    public function test_each_kind_round_trips(string $storage): void
    {
        $this->roundTrip($this->dm($storage));
    }

    #[Group('mysql')]
    public function test_each_kind_round_trips_on_mysql(): void
    {
        $this->roundTrip($this->dm('mysql'));
    }

    #[DataProvider('storages')]
    public function test_a_save_refuses_an_invalid_link_on_the_field(string $storage): void
    {
        $dm = $this->dm($storage);
        try {
            $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('teaser'), ['uuid' => 't1', 'cta' => ['kind' => 'url', 'target' => 'javascript:alert(1)']]));
            $this->fail('saved an unsafe address');
        } catch (ValidationException $e) {
            $this->assertSame(['cta' => ['Must be a web address starting with https:// or http://, or a path on this site starting with /.']], $e->errors());
        }
        $this->assertNull($dm->findType('teaser', 't1'));
    }

    public function test_the_column_is_text(): void
    {
        $dm = $this->dm('json');
        $field = $dm->getTypeRegistry()->get('teaser')->fields['cta'];

        $this->assertSame(ColumnKind::Text, $dm->fieldKinds()->columnKindOf($field));
        $this->assertSame(ColumnKind::Text, $dm->fieldKinds()->columnKindOf($field->withIndex()), 'never a short column, even indexed');
    }

    private function roundTrip(DataManager $dm): void
    {
        $def = $dm->getTypeRegistry()->get('teaser');
        $links = [];
        foreach (LinkTest::kinds() as $kind => [$link]) {
            $links[$kind] = $link;
            $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 't-' . $kind, 'title' => $kind, 'cta' => $link->toStored()]));
        }
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 't-none', 'title' => 'none', 'cta' => null]));

        foreach ($links as $kind => $link) {
            $this->assertEquals($link, Link::fromStored($dm->findType('teaser', 't-' . $kind)?->get('cta')), $kind);
        }
        $this->assertNull(Link::fromStored($dm->findType('teaser', 't-none')?->get('cta')));
    }

    private function dm(string $storage): DataManager
    {
        file_put_contents($this->dir . '/models/teaser.json', (string) json_encode([
            'key' => 'teaser', 'table' => 'teaser', 'storage' => $storage, 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'cta' => ['type' => 'link']],
        ]));
        $driver = match ($storage) {
            'json' => new JsonFileDriver($this->dir . '/data'),
            'sqlite' => new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/app.sqlite')),
            'mysql' => new MysqlDriver(MysqlTestDatabase::pdo()),
        };
        $this->mysql = $storage === 'mysql';
        $dm = new DataManager([$storage => $driver], $storage, new TypeRegistry($this->dir . '/models'), validatorFactory: new ValidatorFactory(new RuleFactory()));
        FieldsServiceProvider::registerKinds($dm->fieldKinds());
        if ($storage !== 'json') {
            (new RecordTableSync($dm))->sync($dm->getTypeRegistry()->get('teaser'));
        }

        return $dm;
    }
}
