<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\DevTools\Command\MakeModelCommand;

/**
 * `preflow make:model <Class> --from-type=<key>`: the class and the admin-only file it leaves
 * give the registry exactly the type the JSON file gave, so a site moves a type without a data change.
 */
final class MakeModelFromTypeTest extends TestCase
{
    private string $base;
    private string $fixtureDir;
    /** @var resource */
    private mixed $stderr;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/Fixtures/types';
        $this->base = sys_get_temp_dir() . '/make_model_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/config/models', 0777, true);
        $this->stderr = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    /** @return array<string, array{string, string}> fixture, class name ("Object" is reserved in PHP) */
    public static function types(): array
    {
        return [
            'holz-objekte object' => [__DIR__ . '/Fixtures/types/object.json', 'WoodObject'],
            'bggenius game' => [__DIR__ . '/Fixtures/types/game.json', 'Game'],
            'translatable, matrix, blocks, scoped unique, label map, composite indexes' => [__DIR__ . '/Fixtures/types/article.json', 'Article'],
        ];
    }

    #[DataProvider('types')]
    public function test_the_generated_class_round_trips_to_the_same_type(string $fixture, string $class): void
    {
        $original = json_decode(file_get_contents($fixture), true);
        $key = $original['key'];
        copy($fixture, $this->base . "/config/models/{$key}.json");

        [$code, $out] = $this->make($class, '--from-type=' . $key);

        $this->assertSame(0, $code, $out . $this->errors());
        require $this->base . "/app/Models/{$class}.php";
        $registry = new TypeRegistry($this->base . '/config/models', null, [$key => 'App\\Models\\' . $class]);
        $this->assertEquals((new TypeRegistry($this->fixtureDir))->get($key), $registry->get($key));
        $this->assertSame(
            array_keys((new TypeRegistry($this->fixtureDir))->get($key)->fields),
            array_keys($registry->get($key)->fields),
            'fields keep their order (the admin form shows them so)',
        );
        $this->assertEquals(
            self::withDefaults((new TypeRegistry($this->fixtureDir))->schema($key), $key),
            self::withDefaults($registry->schema($key), $key),
            'the schema array Folio reads is the same, falsy and null keys too',
        );
        $admin = json_decode(file_get_contents($this->base . "/config/models/{$key}.json"), true);
        $this->assertSame([], array_intersect(array_keys($admin), ['table', 'storage', 'id_field']));
        $this->assertSame($key, $admin['key']);
    }

    public function test_presentation_stays_in_the_admin_file_and_data_goes_on_the_class(): void
    {
        copy($this->fixtureDir . '/object.json', $this->base . '/config/models/object.json');

        [$code, $out] = $this->make('WoodObject', '--from-type=object');

        $this->assertSame(0, $code, $this->errors());
        $this->assertSame(
            "Model created: app/Models/WoodObject.php\n"
            . "Admin settings kept in: config/models/object.json\n"
            . "Check the class, then run your tests: nothing in the database changes.\n",
            $out,
        );
        $admin = json_decode(file_get_contents($this->base . '/config/models/object.json'), true);
        $this->assertSame('Objects', $admin['label']);
        $this->assertSame(['columns' => ['title', 'city', 'state'], 'filters' => ['owner_deleted'], 'sort' => '-created_at'], $admin['list']);
        $this->assertSame(['readonly' => true], $admin['fields']['owner_deleted']);
        $this->assertArrayNotHasKey('title', $admin['fields'], 'a field with nothing but data has no admin entry');

        $php = file_get_contents($this->base . '/app/Models/WoodObject.php');
        $this->assertStringContainsString("namespace App\\Models;", $php);
        $this->assertStringContainsString("#[Entity(table: 'object', storage: 'default', type: 'object')]", $php);
        $this->assertStringContainsString("final class WoodObject extends Model", $php);
        $this->assertStringContainsString("    #[Id]\n    public ?string \$uuid = null;", $php);
        $this->assertStringContainsString("    #[Field(label: 'Title')]\n    #[Validate('required')]\n    public ?string \$title = null;", $php);
        $this->assertStringContainsString("    #[Field(label: 'Category')]\n    #[Relation(to: 'category', labelField: 'title')]\n    public ?string \$category = null;", $php);
    }

    public function test_an_empty_object_in_the_admin_file_stays_an_object(): void
    {
        copy($this->fixtureDir . '/article.json', $this->base . '/config/models/article.json');

        $this->make('Article', '--from-type=article');

        $this->assertStringContainsString('"list": {}', file_get_contents($this->base . '/config/models/article.json'));
    }

    public function test_a_key_that_decides_the_column_goes_on_the_class(): void
    {
        copy($this->fixtureDir . '/article.json', $this->base . '/config/models/article.json');

        $this->make('Article', '--from-type=article');

        $admin = json_decode(file_get_contents($this->base . '/config/models/article.json'), true);
        $this->assertArrayNotHasKey('tags', $admin['fields'], '"multiple" is schema: a multiple relation is a text column (FieldKinds)');
        $this->assertStringContainsString(
            "    #[Field(type: 'relation', config: ['multiple' => true])]\n    #[Relation(to: 'tag', onDelete: 'detach', labelField: 'name')]\n    public ?array \$tags = null;",
            file_get_contents($this->base . '/app/Models/Article.php'),
        );
    }

    public function test_every_property_is_nullable_and_starts_with_the_fields_default(): void
    {
        copy($this->fixtureDir . '/article.json', $this->base . '/config/models/article.json');

        $this->make('Article', '--from-type=article');

        $php = file_get_contents($this->base . '/app/Models/Article.php');
        $this->assertStringContainsString("    #[Validate('required', 'max:120')]\n    public array|string|null \$title = null;", $php);
        $this->assertStringContainsString("    #[Validate('required')]\n    public int|float|null \$rating = 3;", $php);
        $this->assertStringContainsString("    public ?bool \$featured = false;", $php);
        $this->assertStringContainsString("config: ['options' => ['news' => 'News', 'guides' => 'Guides'], 'default' => null],\n    )]\n    public ?string \$section = null;", $php);
    }

    public function test_a_field_that_holds_a_list_is_an_array_property(): void
    {
        copy($this->fixtureDir . '/object.json', $this->base . '/config/models/object.json');
        copy($this->fixtureDir . '/article.json', $this->base . '/config/models/article.json');
        copy($this->fixtureDir . '/game.json', $this->base . '/config/models/game.json');

        $this->make('WoodObject', '--from-type=object');
        $this->make('Article', '--from-type=article');
        $this->make('Game', '--from-type=game');

        $this->assertStringContainsString('    public ?array $images = null;', file_get_contents($this->base . '/app/Models/WoodObject.php'), 'multiple assets');
        $article = file_get_contents($this->base . '/app/Models/Article.php');
        $this->assertStringContainsString('    public ?array $gallery = null;', $article, 'a matrix');
        $this->assertStringContainsString('    public array|string|null $body = null;', $article, 'translatable blocks: a locale map or the plain value');
        $this->assertStringContainsString('    public ?string $cover = null;', file_get_contents($this->base . '/app/Models/Game.php'), 'one asset is its id');
    }

    public function test_a_generated_class_reads_and_keeps_its_list_fields_on_sql(): void
    {
        file_put_contents($this->base . '/config/models/album.json', json_encode(['key' => 'album', 'table' => 'album', 'fields' => [
            'title' => ['type' => 'string'],
            'images' => ['type' => 'asset', 'asset' => ['multiple' => true]],
            'body' => ['type' => 'blocks'],
            'tags' => ['type' => 'relation', 'multiple' => true, 'relation' => ['to' => 'album', 'onDelete' => 'detach']],
        ]]));
        [$code] = $this->make('ListAlbum', '--from-type=album');
        $this->assertSame(0, $code, $this->errors());
        require $this->base . '/app/Models/ListAlbum.php';

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE album (uuid TEXT PRIMARY KEY, title TEXT, images TEXT, body TEXT, tags TEXT)');
        $pdo->exec('INSERT INTO album VALUES (\'a1\', \'Old\', \'["m1","m2"]\', \'[{"_block":"text","text":"Hi"}]\', \'["a2"]\')');
        $types = new TypeRegistry($this->base . '/config/models', new Locales(['en'], 'en'), ['album' => \App\Models\ListAlbum::class]);
        $dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default', $types);

        $album = $dm->find(\App\Models\ListAlbum::class, 'a1');
        $this->assertSame(['m1', 'm2'], $album->images);
        $this->assertSame([['_block' => 'text', 'text' => 'Hi']], $album->body);
        $this->assertSame(['a2'], $album->tags);
        $album->title = 'New';
        $dm->save($album, validate: false);

        $this->assertSame(
            ['uuid' => 'a1', 'title' => 'New', 'images' => '["m1","m2"]', 'body' => '[{"_block":"text","text":"Hi"}]', 'tags' => '["a2"]'],
            $pdo->query("SELECT * FROM album WHERE uuid = 'a1'")->fetch(\PDO::FETCH_ASSOC),
        );
    }

    public function test_a_stored_null_in_a_required_field_survives_a_typed_find_and_save(): void
    {
        file_put_contents($this->base . '/config/models/note.json', json_encode(['key' => 'note', 'table' => 'note', 'fields' => [
            'title' => ['type' => 'string', 'validate' => ['required']],
            'count' => ['type' => 'number', 'validate' => ['required']],
            'done' => ['type' => 'boolean', 'validate' => ['required']],
            'tags' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
            'body' => ['type' => 'text'],
        ]]));
        [$code] = $this->make('NullSafeNote', '--from-type=note');
        $this->assertSame(0, $code, $this->errors());
        require $this->base . '/app/Models/NullSafeNote.php';

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE note (uuid TEXT PRIMARY KEY, title TEXT, count INTEGER, done INTEGER, tags TEXT, body TEXT)');
        $pdo->exec("INSERT INTO note (uuid) VALUES ('n1')");
        $types = new TypeRegistry($this->base . '/config/models', new Locales(['en', 'de'], 'en'), ['note' => \App\Models\NullSafeNote::class]);
        $dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default', $types);

        $note = $dm->find(\App\Models\NullSafeNote::class, 'n1');
        $note->body = 'Edited';
        $dm->save($note, validate: false);

        $this->assertSame(
            ['uuid' => 'n1', 'title' => null, 'count' => null, 'done' => null, 'tags' => null, 'body' => 'Edited'],
            $pdo->query("SELECT * FROM note WHERE uuid = 'n1'")->fetch(\PDO::FETCH_ASSOC),
        );
    }

    public function test_it_refuses_a_key_a_class_under_the_model_paths_already_has(): void
    {
        copy($this->fixtureDir . '/game.json', $this->base . '/config/models/game.json');
        mkdir($this->base . '/src/Domain', 0777, true);
        file_put_contents($this->base . '/src/Domain/Play.php', "<?php\n#[Entity(table: 'game', type: 'game')]\nfinal class Play {}\n");
        file_put_contents($this->base . '/config/data.php', "<?php\nreturn ['model_paths' => [__DIR__ . '/../src/Domain', __DIR__ . '/../app/Models']];\n");

        [$code] = $this->make('Game', '--from-type=game');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Type "game" is already defined by a model class (', $this->errors());
        $this->assertStringContainsString('/config/../src/Domain/Play.php)', $this->errors());
        $this->assertFileEquals($this->fixtureDir . '/game.json', $this->base . '/config/models/game.json');
    }

    public function test_relative_model_paths_are_under_the_app(): void
    {
        copy($this->fixtureDir . '/game.json', $this->base . '/config/models/game.json');
        mkdir($this->base . '/src/Domain', 0777, true);
        file_put_contents($this->base . '/src/Domain/Play.php', "<?php\n#[Entity(table: 'game', type: 'game')]\nfinal class Play {}\n");
        file_put_contents($this->base . '/config/data.php', "<?php\nreturn ['model_paths' => ['src/Domain', 'app/Models']];\n");

        [$code] = $this->make('Game', '--from-type=game');

        $this->assertSame(1, $code, 'app/Models is a model path, and src/Domain is searched under the app, not the working directory');
        $this->assertStringContainsString('Type "game" is already defined by a model class (' . $this->base . '/src/Domain/Play.php)', $this->errors());
    }

    public function test_it_refuses_when_app_models_is_not_a_model_path(): void
    {
        copy($this->fixtureDir . '/game.json', $this->base . '/config/models/game.json');
        file_put_contents($this->base . '/config/data.php', "<?php\nreturn ['model_paths' => [__DIR__ . '/../src/Domain']];\n");

        [$code] = $this->make('Game', '--from-type=game');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('app/Models is not under data.model_paths', $this->errors());
        $this->assertFileDoesNotExist($this->base . '/app/Models/Game.php');
    }

    public function test_it_refuses_an_existing_class(): void
    {
        copy($this->fixtureDir . '/game.json', $this->base . '/config/models/game.json');
        mkdir($this->base . '/app/Models', 0777, true);
        file_put_contents($this->base . '/app/Models/Game.php', "<?php // mine\n");

        [$code] = $this->make('Game', '--from-type=game');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Model Game already exists', $this->errors());
        $this->assertSame("<?php // mine\n", file_get_contents($this->base . '/app/Models/Game.php'));
        $this->assertFileEquals($this->fixtureDir . '/game.json', $this->base . '/config/models/game.json');
    }

    public function test_it_refuses_a_key_that_is_already_class_backed(): void
    {
        copy($this->fixtureDir . '/game.json', $this->base . '/config/models/game.json');
        $this->make('Game', '--from-type=game');
        $admin = file_get_contents($this->base . '/config/models/game.json');

        [$code] = $this->make('BoardGame', '--from-type=game');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Type "game" is already defined by a model class', $this->errors());
        $this->assertFileDoesNotExist($this->base . '/app/Models/BoardGame.php');
        $this->assertSame($admin, file_get_contents($this->base . '/config/models/game.json'));
    }

    public function test_from_type_keeps_matrixable_in_the_admin_file(): void
    {
        $type = json_decode(file_get_contents($this->fixtureDir . '/game.json'), true);
        $type['matrixable'] = true;
        $type['views'] = ['card'];
        file_put_contents($this->base . '/config/models/game.json', json_encode($type));

        [$code, $out] = $this->make('MatrixGame', '--from-type=game');

        $this->assertSame(0, $code, $out . $this->errors());
        $admin = json_decode(file_get_contents($this->base . '/config/models/game.json'), true);
        $this->assertTrue($admin['matrixable']);
        $this->assertSame(['card'], $admin['views']);
        require $this->base . '/app/Models/MatrixGame.php';
        $schema = (new TypeRegistry($this->base . '/config/models', null, ['game' => 'App\\Models\\MatrixGame']))->schema('game');
        $this->assertTrue($schema['matrixable']);
        $this->assertSame(['card'], $schema['views']);
    }

    public function test_an_id_field_that_is_also_a_field_is_an_error(): void
    {
        $type = json_decode(file_get_contents($this->fixtureDir . '/game.json'), true);
        $type['id_field'] = 'slug';
        file_put_contents($this->base . '/config/models/game.json', json_encode($type));

        [$code] = $this->make('Game', '--from-type=game');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('"slug"', $this->errors());
        $this->assertFileDoesNotExist($this->base . '/app/Models/Game.php');
    }

    public function test_it_refuses_a_class_name_php_reserves(): void
    {
        copy($this->fixtureDir . '/object.json', $this->base . '/config/models/object.json');

        [$code] = $this->make('Object', '--from-type=object');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Object is a reserved word in PHP', $this->errors());
        $this->assertFileDoesNotExist($this->base . '/app/Models/Object.php');
    }

    public function test_an_unknown_type_is_an_error(): void
    {
        [$code] = $this->make('Game', '--from-type=game');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No model file config/models/game.json', $this->errors());
    }

    public function test_a_patch_file_is_not_a_type(): void
    {
        file_put_contents($this->base . '/config/models/page.json', json_encode(['key' => 'page', 'extends' => true, 'fields' => ['x' => ['type' => 'string']]]));

        [$code] = $this->make('Page', '--from-type=page');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('"extends"', $this->errors());
    }

    /**
     * The defaults the registry applies and a class always states, filled in.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function withDefaults(array $schema, string $key): array
    {
        $schema += ['key' => $key, 'storage' => 'default', 'id_field' => 'uuid'];
        foreach ($schema['fields'] as $name => $field) {
            $schema['fields'][$name] = $field + ['type' => 'string'];
        }

        return $schema;
    }

    /** @return array{int, string} */
    private function make(string ...$args): array
    {
        ob_start();
        $code = (new MakeModelCommand($this->base, $this->stderr))->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    private function errors(): string
    {
        rewind($this->stderr);

        return (string) stream_get_contents($this->stderr);
    }
}
