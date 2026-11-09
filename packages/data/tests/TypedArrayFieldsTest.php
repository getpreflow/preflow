<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Locales;
use Preflow\Data\Tests\Fixtures\ModelBridge\Gallery;
use Preflow\Data\Tests\Fixtures\ModelBridge\Ticket;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/**
 * Array fields (assets, blocks, a multiple relation) of a class-backed type read back as
 * arrays and survive a save that does not touch them; an auto-increment id saves twice.
 */
final class TypedArrayFieldsTest extends TestCase
{
    private string $dir;

    private DataManager $dm;

    private ?\PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        // The monorepo's root autoloader does not map this package's test namespace.
        require_once __DIR__ . '/Fixtures/ModelBridge/Gallery.php';
        require_once __DIR__ . '/Fixtures/ModelBridge/Ticket.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typed_arrays_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'json' => ['json'];
    }

    private function boot(string $storage): void
    {
        if ($storage === 'sqlite') {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('CREATE TABLE gallery (uuid TEXT PRIMARY KEY, title TEXT, images TEXT, body TEXT, related TEXT, _version INTEGER)');
            $this->pdo->exec('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
            $driver = new SqliteDriver($this->pdo);
        } else {
            $driver = new JsonFileDriver($this->dir . '/store');
        }
        $types = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'), ['gallery' => Gallery::class, 'ticket' => Ticket::class]);
        $this->dm = new DataManager(['default' => $driver], 'default', $types, new ValidatorFactory(new RuleFactory()));
    }

    #[DataProvider('storages')]
    public function test_array_fields_read_back_as_arrays(string $storage): void
    {
        $this->boot($storage);
        $gallery = new Gallery();
        $gallery->uuid = 'g1';
        $gallery->title = 'Old';
        $gallery->images = ['m1', 'm2'];
        $gallery->body = [['type' => 'text', 'data' => ['text' => 'Hello']]];
        $gallery->related = ['g2'];
        $this->dm->save($gallery);

        $read = $this->dm->find(Gallery::class, 'g1');

        $this->assertSame(['m1', 'm2'], $read?->images);
        $this->assertSame([['type' => 'text', 'data' => ['text' => 'Hello']]], $read->body);
        $this->assertSame(['g2'], $read->related);
    }

    #[DataProvider('storages')]
    public function test_a_save_that_changes_only_the_title_keeps_the_array_fields(string $storage): void
    {
        $this->boot($storage);
        $gallery = new Gallery();
        $gallery->uuid = 'g1';
        $gallery->title = 'Old';
        $gallery->images = ['m1', 'm2'];
        $gallery->body = [['type' => 'text', 'data' => ['text' => 'Hällo']]];
        $gallery->related = ['g2'];
        $this->dm->save($gallery);
        $before = $this->dm->driver('default')->findOne('gallery', 'g1');

        $read = $this->dm->find(Gallery::class, 'g1');
        $read->title = 'New';
        $this->dm->save($read);

        $after = $this->dm->driver('default')->findOne('gallery', 'g1');
        $this->assertSame('New', $after['title']);
        unset($before['title'], $after['title']);
        $this->assertSame($before, $after, 'the stored array fields stay byte-identical');
    }

    #[DataProvider('storages')]
    public function test_a_value_hydrate_skipped_is_not_written_over(string $storage): void
    {
        $this->boot($storage);
        $this->dm->driver('default')->save('gallery', 'g1', ['uuid' => 'g1', 'title' => 'Old', 'images' => 'not json', 'body' => null], 'uuid');

        $read = $this->dm->find(Gallery::class, 'g1');
        $this->assertNull($read->images, 'a value the property cannot take leaves its default');
        $read->title = 'New';
        $this->dm->save($read);
        $this->assertSame('not json', $this->dm->driver('default')->findOne('gallery', 'g1')['images'], 'an untouched skipped field keeps its stored value');

        $read->images = ['m1'];
        $this->dm->save($read);
        $stored = $this->dm->driver('default')->findOne('gallery', 'g1')['images'];
        $this->assertSame(['m1'], is_string($stored) ? json_decode($stored, true) : $stored, 'a skipped field the code changed is written');
    }

    public function test_an_auto_increment_id_inserts_updates_and_saves_again(): void
    {
        $this->boot('sqlite');
        $ticket = new Ticket();
        $ticket->title = 'a';
        $this->dm->save($ticket);
        $this->assertSame(1, $ticket->id);

        $ticket->title = 'b';
        $this->dm->update($ticket);
        $this->assertSame('b', $this->dm->find(Ticket::class, 1)?->title);

        $ticket->title = 'c';
        $this->dm->save($ticket);
        $found = $this->dm->find(Ticket::class, 1);
        $this->assertSame('c', $found?->title);
        $this->assertSame(1, $found->id);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ticket')->fetchColumn());
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
