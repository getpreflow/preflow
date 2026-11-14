<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;

/** A relation without labelField matches the target's titleField before its first string field. */
final class RelationTitleFieldSearchTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rtf_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        $rm = function (string $p) use (&$rm): void {
            foreach (glob($p . '/*') ?: [] as $f) {
                is_dir($f) ? $rm($f) : unlink($f);
            }
            @rmdir($p);
        };
        $rm($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    /** @return list<string> */
    private function find(string $storage, ?string $titleField, string $term): array
    {
        $designer = ['key' => 'designer', 'table' => 'designer', 'storage' => $storage, 'fields' => [
            'nick' => ['type' => 'string'],
            // Not searched as a text field: only reachable as the label.
            'name' => ['type' => 'string', 'searchable' => false],
        ]];
        if ($titleField !== null) {
            $designer['titleField'] = $titleField;
        }
        file_put_contents($this->dir . '/models/designer.json', json_encode($designer));
        file_put_contents($this->dir . '/models/game.json', json_encode(['key' => 'game', 'table' => 'game', 'storage' => $storage, 'fields' => [
            'title' => ['type' => 'string'],
            'designer' => ['type' => 'relation', 'relation' => ['to' => 'designer']],
        ]]));

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE designer (uuid TEXT PRIMARY KEY, nick TEXT, name TEXT)');
        $pdo->exec('CREATE TABLE game (uuid TEXT PRIMARY KEY, title TEXT, designer TEXT)');
        $registry = new TypeRegistry($this->dir . '/models');
        $dm = new DataManager(['sqlite' => new SqliteDriver($pdo), 'json' => new JsonFileDriver($this->dir . '/store')], 'sqlite', $registry);
        $dm->saveType(DynamicRecord::fromArray($registry->get('designer'), ['uuid' => 'd1', 'nick' => 'vl', 'name' => 'Vital Lacerda']), validate: false);
        $dm->saveType(DynamicRecord::fromArray($registry->get('game'), ['uuid' => 'g1', 'title' => 'Lisboa', 'designer' => 'd1']), validate: false);

        return array_map(static fn ($r) => $r->getId(), $dm->queryType('game')->searchLoose($term)->get()->items());
    }

    #[DataProvider('storages')]
    public function test_the_target_title_field_is_matched(string $storage): void
    {
        $this->assertSame(['g1'], $this->find($storage, 'name', 'lacerda'));
    }

    #[DataProvider('storages')]
    public function test_without_title_field_the_first_string_field_is_matched(string $storage): void
    {
        $this->assertSame([], $this->find($storage, null, 'lacerda'));
        $this->assertSame(['g1'], $this->find($storage, null, 'vl'));
    }
}
