<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Search\RecordHit;
use Preflow\Data\Search\RecordSearch;
use Preflow\Data\TypeRegistry;

final class RecordSearchTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rs_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    private function search(string $storage): RecordSearch
    {
        $this->model('game', $storage, ['title' => ['type' => 'string', 'translatable' => true], 'notes' => ['type' => 'text']], 'title');
        $this->model('designer', $storage, ['pin' => ['type' => 'string', 'hidden' => true], 'name' => ['type' => 'string']]);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE game (uuid TEXT PRIMARY KEY, title TEXT, notes TEXT, _locales TEXT)');
        $pdo->exec('CREATE TABLE designer (uuid TEXT PRIMARY KEY, pin TEXT, name TEXT)');

        $registry = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'));
        $dm = new DataManager(
            drivers: ['sqlite' => new SqliteDriver($pdo), 'json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'sqlite',
            typeRegistry: $registry,
        );
        foreach ([
            ['uuid' => 'g1', 'title' => ['en' => 'Zoo Night', 'de' => 'Zoonacht'], 'notes' => ''],
            ['uuid' => 'g2', 'title' => ['en' => 'Zoo Day', 'de' => 'Zootag'], 'notes' => ''],
            ['uuid' => 'g3', 'title' => ['en' => 'Lisboa', 'de' => 'Lissabon'], 'notes' => 'zoo mentioned'],
            ['uuid' => 'g4', 'title' => ['en' => 'Kanban', 'de' => 'Kanban'], 'notes' => ''],
        ] as $row) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('game'), $row), validate: false);
        }
        foreach ([
            ['uuid' => 'd1', 'pin' => 'p1', 'name' => 'Zoe Zookeeper'],
            ['uuid' => 'd2', 'pin' => 'p2', 'name' => 'Uwe Rosenberg'],
        ] as $row) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('designer'), $row), validate: false);
        }

        return new RecordSearch($dm);
    }

    /** @param list<RecordHit> $hits @return list<string> "type:id" */
    private static function keys(array $hits): array
    {
        return array_map(static fn (RecordHit $h): string => $h->type . ':' . $h->id, $hits);
    }

    #[DataProvider('storages')]
    public function test_searches_two_types_with_titles(string $storage): void
    {
        $hits = $this->search($storage)->search('zoo', ['game', 'designer']);

        $this->assertContainsOnlyInstancesOf(RecordHit::class, $hits);
        $byKey = [];
        foreach ($hits as $hit) {
            $byKey[$hit->type . ':' . $hit->id] = $hit->title;
        }
        ksort($byKey);
        $this->assertSame([
            'designer:d1' => 'Zoe Zookeeper',
            'game:g1' => 'Zoo Night',
            'game:g2' => 'Zoo Day',
            'game:g3' => 'Lisboa',
        ], $byKey);
    }

    #[DataProvider('storages')]
    public function test_per_type_caps_each_type(string $storage): void
    {
        $hits = $this->search($storage)->search('zoo', ['game', 'designer'], perType: 2);

        $types = array_map(static fn (RecordHit $h): string => $h->type, $hits);
        $this->assertSame(['game', 'game', 'designer'], $types);
    }

    #[DataProvider('storages')]
    public function test_hits_follow_the_given_type_order(string $storage): void
    {
        $hits = $this->search($storage)->search('zoo', ['designer', 'game']);

        $types = array_map(static fn (RecordHit $h): string => $h->type, $hits);
        $this->assertSame(['designer', 'game', 'game', 'game'], $types);
    }

    #[DataProvider('storages')]
    public function test_a_term_under_two_characters_finds_nothing(string $storage): void
    {
        $search = $this->search($storage);

        $this->assertSame([], $search->search('z', ['game', 'designer']));
        $this->assertSame([], $search->search('  z  ', ['game', 'designer']));
        $this->assertSame([], $search->search('   ', ['game', 'designer']));
        $this->assertNotSame([], $search->search(' zo ', ['game']));
    }

    #[DataProvider('storages')]
    public function test_an_unknown_type_is_skipped(string $storage): void
    {
        $hits = $this->search($storage)->search('rosenberg', ['nope', 'designer']);

        $this->assertSame(['designer:d2'], self::keys($hits));
        $this->assertSame('Uwe Rosenberg', $hits[0]->title);
    }

    public function test_a_data_manager_without_types_finds_nothing(): void
    {
        $search = new RecordSearch(new DataManager(['json' => new JsonFileDriver($this->dir . '/store')], 'json'));

        $this->assertSame([], $search->search('zoo', ['game']));
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function model(string $key, string $storage, array $fields, ?string $titleField = null): void
    {
        $schema = ['key' => $key, 'table' => $key, 'storage' => $storage, 'id_field' => 'uuid', 'fields' => $fields];
        if ($titleField !== null) {
            $schema['titleField'] = $titleField;
        }
        file_put_contents($this->dir . '/models/' . $key . '.json', json_encode($schema));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
