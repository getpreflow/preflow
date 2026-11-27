<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Records\RecordOutput;
use Preflow\Data\Records\RecordParts;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;
use Preflow\Fields\FieldsServiceProvider;

/**
 * The twelve field types read out by the one read class (RecordOutput, 0.93) in their frozen
 * version 1 shapes, from rows as older data holds them (structured values as JSON text, written
 * straight to the driver), the same on JSON and SQLite.
 */
final class FieldsRecordOutputTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fields_output_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
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
    public function test_output_is_the_same_on_json_and_sqlite(string $storage): void
    {
        $link = ['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1'], 'label' => 'About', 'new_tab' => false];
        $phone = ['as_typed' => '+41 44 123 45 67', 'e164' => '+41441234567'];
        $embed = ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'poster' => null];
        $table = ['head' => ['Day', 'Hours'], 'rows' => [['Mon', '9-5']]];
        $location = ['lat' => 47.37, 'lng' => 8.54, 'address' => 'Bahnhofstrasse 1'];
        $rows = [
            // field type => [stored as older data holds it, output]
            'link' => [json_encode($link), $link],
            'email' => ['team@example.org', 'team@example.org'],
            'phone' => [json_encode($phone), $phone],
            'color' => ['#cc0000', '#cc0000'],
            'code' => ["<?php\n\techo 1;", "<?php\n\techo 1;"],
            'json' => ['{"b":1,"a":["x",null]}', ['b' => 1, 'a' => ['x', null]]],
            'datetime' => ['2026-03-29T12:30:00Z', '2026-03-29T12:30:00Z'],
            'time' => ['09:30', '09:30'],
            'daterange' => ['{"start":"2026-03-01","end":"2026-03-05"}', ['start' => '2026-03-01', 'end' => '2026-03-05']],
            'embed' => [json_encode($embed), $embed],
            'table' => [json_encode($table), $table],
            'location' => ['{"lat":"47.37","lng":8.54,"address":"Bahnhofstrasse 1"}', $location],
        ];
        $dm = $this->dm($storage, array_keys($rows));
        $row = ['uuid' => 'r1'];
        $expected = ['_id' => 'r1'];
        foreach ($rows as $type => [$stored, $output]) {
            $row[$type . 'x'] = $stored;
            $expected[$type . 'x'] = $output;
        }
        $dm->driver($storage)->save('allfields', 'r1', $row);

        $out = (new RecordOutput($dm, new RecordParts()))->toArray($dm->findType('all', 'r1') ?? self::fail('no record'));

        $this->assertSame($expected, $out, $storage);
        $this->assertSame($out, json_decode((string) json_encode($out, JSON_THROW_ON_ERROR), true));
    }

    /** @param list<string> $types */
    private function dm(string $storage, array $types): DataManager
    {
        $fields = [];
        foreach ($types as $type) {
            $fields[$type . 'x'] = ['type' => $type];
        }
        file_put_contents($this->dir . '/models/all.json', (string) json_encode([
            'key' => 'all', 'table' => 'allfields', 'storage' => $storage, 'id_field' => 'uuid', 'fields' => $fields,
        ]));
        $driver = $storage === 'json'
            ? new JsonFileDriver($this->dir . '/data')
            : new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/app.sqlite'));
        $dm = new DataManager([$storage => $driver], $storage, new TypeRegistry($this->dir . '/models'));
        FieldsServiceProvider::registerKinds($dm->fieldKinds());
        if ($storage !== 'json') {
            (new RecordTableSync($dm))->sync($dm->getTypeRegistry()->get('all'));
        }

        return $dm;
    }
}
