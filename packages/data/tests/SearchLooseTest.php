<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Model;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeRegistry;

#[Entity(table: 'loose_notes', storage: 'sqlite')]
final class LooseNote extends Model
{
    #[Id]
    public string $uuid = '';
    #[Field(searchable: true)]
    public string $title = '';
    #[Field(searchable: false)]
    public string $secret = '';
    #[Field]
    public int $stars = 0;
}

final class SearchLooseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sl_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /** @return iterable<string, array{string, string}> */
    public static function storages(): iterable
    {
        yield 'sqlite game, json designer' => ['sqlite', 'json'];
        yield 'json game, sqlite designer' => ['json', 'sqlite'];
        yield 'all sqlite' => ['sqlite', 'sqlite'];
        yield 'all json' => ['json', 'json'];
    }

    private function dm(string $gameStorage, string $designerStorage): DataManager
    {
        $this->model('game', $gameStorage, [
            'title' => ['type' => 'string', 'translatable' => true],
            'notes' => ['type' => 'text', 'searchable' => false],
            'stars' => ['type' => 'integer'],
            'designer' => ['type' => 'relation', 'relation' => ['to' => 'designer', 'multiple' => true]],
            'publisher' => ['type' => 'relation', 'relation' => ['to' => 'designer']],
        ]);
        $this->model('designer', $designerStorage, [
            // First string field, but hidden: never the fallback label a relation search matches on.
            'pin' => ['type' => 'string', 'hidden' => true],
            'name' => ['type' => 'string'],
            'bio' => ['type' => 'text'],
            'badge' => ['type' => 'string', 'hidden' => true, 'searchable' => true],
        ]);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE game (uuid TEXT PRIMARY KEY, title TEXT, notes TEXT, stars INTEGER, designer TEXT, publisher TEXT, _locales TEXT)');
        $pdo->exec('CREATE TABLE designer (uuid TEXT PRIMARY KEY, pin TEXT, name TEXT, bio TEXT, badge TEXT)');
        $pdo->exec('CREATE TABLE loose_notes (uuid TEXT PRIMARY KEY, title TEXT, secret TEXT, stars INTEGER)');

        $registry = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'));
        $dm = new DataManager(
            drivers: ['sqlite' => new SqliteDriver($pdo), 'json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'sqlite',
            typeRegistry: $registry,
        );

        foreach ([
            ['uuid' => 'd1', 'pin' => 'pin-lacerda', 'name' => 'Vital Lacerda', 'bio' => 'Portuguese', 'badge' => 'badge-one'],
            ['uuid' => 'd2', 'pin' => 'pin-rosenberg', 'name' => 'Uwe Rosenberg', 'bio' => 'Farmer', 'badge' => ''],
        ] as $row) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('designer'), $row), validate: false);
        }
        foreach ([
            ['uuid' => 'g1', 'title' => ['en' => 'Night at the Zoo', 'de' => 'Nacht im Zoo'], 'notes' => 'hidden-word', 'designer' => '["d2"]', 'publisher' => 'd2'],
            ['uuid' => 'g2', 'title' => ['en' => 'Kanban', 'de' => 'Kanban'], 'notes' => '', 'designer' => '["d1","d2"]', 'publisher' => 'd1'],
            ['uuid' => 'g3', 'title' => ['en' => 'Lisboa', 'de' => 'Lissabon'], 'notes' => '', 'designer' => '["d1"]', 'publisher' => 'd2'],
        ] as $row) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('game'), $row), validate: false);
        }

        return $dm;
    }

    /** @return list<string> sorted ids */
    private function ids(QueryBuilder $q): array
    {
        $ids = array_map(static fn ($r) => $r->getId(), $q->get()->items());
        sort($ids);

        return $ids;
    }

    #[DataProvider('storages')]
    public function test_every_word_must_match(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        $this->assertSame(['g1'], $this->ids($dm->queryType('game')->searchLoose('night zo')));
        $this->assertSame([], $this->ids($dm->queryType('game')->searchLoose('night kanban')));
        $this->assertSame(['g1'], $this->ids($dm->queryType('game')->searchLoose("  NIGHT \t zoo ")));
    }

    #[DataProvider('storages')]
    public function test_blank_term_adds_no_condition(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        $this->assertCount(3, $dm->queryType('game')->searchLoose("  \t ")->get()->items());
    }

    #[DataProvider('storages')]
    public function test_words_match_through_multiple_relation(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        // publisher is also a relation, so restrict to designer to isolate the multiple one
        $this->assertSame(['g2', 'g3'], $this->ids($dm->queryType('game')->searchLoose('vita cer', ['designer'])));
    }

    #[DataProvider('storages')]
    public function test_words_match_through_single_relation(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        $this->assertSame(['g2'], $this->ids($dm->queryType('game')->searchLoose('lacerda', ['publisher'])));
    }

    #[DataProvider('storages')]
    public function test_words_can_match_different_places(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        $this->assertSame(['g3'], $this->ids($dm->queryType('game')->searchLoose('lisboa lacerda')));
        $this->assertSame(['g1'], $this->ids($dm->queryType('game')->searchLoose('zoo rosenberg')));
        $this->assertSame(['g1'], $this->ids($dm->queryType('game')->searchLoose('zoo farmer')), 'target text field matches too');
    }

    #[DataProvider('storages')]
    public function test_searchable_false_field_is_excluded(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        $this->assertSame([], $this->ids($dm->queryType('game')->searchLoose('hidden-word')));
    }

    #[DataProvider('storages')]
    public function test_hidden_field_is_not_searched_unless_searchable_true(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        $this->assertSame([], $this->ids($dm->queryType('designer')->searchLoose('pin-lacerda')));
        $this->assertSame([], $this->ids($dm->queryType('designer')->searchLoose('pin')));
        $this->assertSame([], $this->ids($dm->queryType('game')->searchLoose('pin-lacerda')), 'not through a relation either');
        $this->assertSame([], $this->ids($dm->queryType('game')->searchLoose('pin')));
        // hidden but "searchable": true opts back in, here and through relations
        $this->assertSame(['d1'], $this->ids($dm->queryType('designer')->searchLoose('badge-one')));
        $this->assertSame(['g2', 'g3'], $this->ids($dm->queryType('game')->searchLoose('badge-one')));
    }

    #[DataProvider('storages')]
    public function test_explicit_fields_drop_hidden_and_searchable_false_fields(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        $this->assertSame([], $this->ids($dm->queryType('designer')->searchLoose('pin-lacerda', ['pin'])));
        $this->assertSame([], $this->ids($dm->queryType('designer')->searchLoose('pin-lacerda', ['name', 'pin'])));
        $this->assertSame(['d1'], $this->ids($dm->queryType('designer')->searchLoose('badge-one', ['badge'])));
        $this->assertSame([], $this->ids($dm->queryType('game')->searchLoose('hidden-word', ['notes'])));
        $this->assertSame([], $this->ids($dm->queryType('game')->searchLoose('hidden-word', ['title', 'notes'])));
    }

    #[DataProvider('storages')]
    public function test_translatable_field_matches_in_requested_locale(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        $this->assertSame(['g1'], $this->ids($dm->queryType('game')->locale('de')->searchLoose('nacht')));
        $this->assertSame([], $this->ids($dm->queryType('game')->searchLoose('nacht')));
        $this->assertSame(['g3'], $this->ids($dm->queryType('game')->searchLoose('lissabon')->locale('de')), 'locale() after searchLoose still counts');
    }

    #[DataProvider('storages')]
    public function test_unknown_field_names_are_dropped(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        $this->assertSame(['g1'], $this->ids($dm->queryType('game')->searchLoose('zoo', ['title', 'x" OR 1=1 --'])));
        $this->assertSame([], $this->ids($dm->queryType('game')->searchLoose('zoo', ['x" OR 1=1 --'])));
    }

    #[DataProvider('storages')]
    public function test_wildcards_and_quotes_match_literally(string $g, string $d): void
    {
        $dm = $this->dm($g, $d);

        foreach (['%', '_', "'", '"', '!', 'ü'] as $word) {
            $this->assertSame([], $this->ids($dm->queryType('game')->searchLoose($word)), $word);
        }
    }

    public function test_search_is_unchanged(): void
    {
        $dm = $this->dm('sqlite', 'json');
        $this->assertCount(3, $dm->queryType('game')->search('zoo')->get()->items(), 'search() with no searchable:true fields keeps its old behaviour');
    }

    public function test_without_data_manager_relations_are_skipped(): void
    {
        $dm = $this->dm('sqlite', 'json');
        $type = $dm->getTypeRegistry()->get('game');
        $driver = new SqliteDriver((function () {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE game (uuid TEXT, title TEXT, notes TEXT, stars INTEGER, designer TEXT, publisher TEXT, _locales TEXT)');
            $pdo->exec("INSERT INTO game (uuid, title, designer) VALUES ('x', 'Zoo', '[\"d1\"]')");
            return $pdo;
        })());

        $this->assertCount(1, QueryBuilder::forType($driver, $type)->searchLoose('zoo')->get()->items());
        $this->assertCount(0, QueryBuilder::forType($driver, $type)->searchLoose('d1')->get()->items());
    }

    public function test_typed_model_uses_string_properties_not_opted_out(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE loose_notes (uuid TEXT PRIMARY KEY, title TEXT, secret TEXT, stars INTEGER)');
        $pdo->exec("INSERT INTO loose_notes VALUES ('a', 'Hello World', 'xyzzy', 5), ('b', 'Other', 'plugh', 7)");
        $dm = new DataManager(drivers: ['sqlite' => new SqliteDriver($pdo)], defaultDriver: 'sqlite');

        $this->assertCount(1, $dm->query(LooseNote::class)->searchLoose('hello wor')->get()->items());
        $this->assertCount(0, $dm->query(LooseNote::class)->searchLoose('xyzzy')->get()->items());
        $this->assertCount(0, $dm->query(LooseNote::class)->searchLoose('5')->get()->items());
        $this->assertCount(0, $dm->query(LooseNote::class)->searchLoose('hello', ['secret'])->get()->items());
        // Naming a searchable:false field explicitly does not search it.
        $this->assertCount(0, $dm->query(LooseNote::class)->searchLoose('xyzzy', ['secret'])->get()->items());
        $this->assertCount(0, $dm->query(LooseNote::class)->searchLoose('xyzzy', ['title', 'secret'])->get()->items());
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function model(string $key, string $storage, array $fields): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", json_encode([
            'key' => $key, 'table' => $key, 'storage' => $storage, 'id_field' => 'uuid', 'fields' => $fields,
        ]));
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
