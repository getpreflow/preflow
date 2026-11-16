<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\VersionFields;

/**
 * JSON record files in one canonical format (0.78): DataManager writes the keys in the type's
 * field order, then the system keys, then unknown keys, with list, number and yes/no values as
 * such; the JSON driver encodes them with stable flags and a trailing newline. SQL storage keeps
 * getting lists as JSON text.
 */
final class CanonicalJsonFilesTest extends TestCase
{
    private const string CANONICAL = "{\n    \"title\": \"T\",\n    \"count\": 2,\n    \"tags\": [\n        \"a\"\n    ],\n    \"uuid\": \"r1\",\n    \"_version\": 1\n}\n";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_canonical_json_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0755, true);
        file_put_contents($this->dir . '/models/item.json', (string) json_encode([
            'key' => 'item', 'table' => 'items', 'storage' => 'default', 'fields' => [
                'title' => ['type' => 'string'],
                'count' => ['type' => 'number'],
                'tags' => ['type' => 'tags'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    private function dm(?StorageDriver $driver = null): DataManager
    {
        $dm = new DataManager(['default' => $driver ?? new JsonFileDriver($this->dir . '/data')], typeRegistry: new TypeRegistry($this->dir . '/models'));
        $dm->fieldKinds()->list('tags');
        $dm->useVersionFields(new class implements VersionFields {
            public function of(TypeDefinition $def): ?string
            {
                return $def->table === 'items' ? '_version' : null;
            }

            public function nonContent(TypeDefinition $def): array
            {
                return [];
            }
        });

        return $dm;
    }

    private function item(DataManager $dm, array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($dm->getTypeRegistry()->get('item'), $data);
    }

    private function file(): string
    {
        return (string) file_get_contents($this->dir . '/data/items/r1.json');
    }

    public function test_save_writes_fields_in_type_order_with_a_trailing_newline(): void
    {
        $dm = $this->dm();
        $dm->saveType($this->item($dm, ['_version' => 1, 'tags' => ['a'], 'uuid' => 'r1', 'count' => 2, 'title' => 'T']));

        $this->assertSame(self::CANONICAL, $this->file());
    }

    public function test_saving_an_unchanged_record_again_gives_the_same_bytes(): void
    {
        $dm = $this->dm();
        $dm->saveType($this->item($dm, ['_version' => 1, 'tags' => ['a'], 'uuid' => 'r1', 'count' => 2, 'title' => 'T']));
        $first = $this->file();

        $dm->saveType($dm->findType('item', 'r1') ?? $this->fail('saved'));
        $this->assertSame($first, $this->file());

        $fresh = $this->dm(); // another process (the CLI) loading and saving it
        $fresh->saveType($fresh->findType('item', 'r1') ?? $this->fail('saved'));
        $this->assertSame($first, $this->file());
    }

    public function test_a_hand_written_file_is_normalised_on_its_next_save(): void
    {
        mkdir($this->dir . '/data/items', 0755, true);
        file_put_contents($this->dir . '/data/items/r1.json', '{"extra":"x","_version":1,"tags":"[\"a\"]","count":"2","uuid":"r1","title":"T"}');
        $dm = $this->dm();

        $dm->saveType($dm->findType('item', 'r1') ?? $this->fail('hand-written file read'));

        $this->assertSame(substr(self::CANONICAL, 0, -3) . ",\n    \"extra\": \"x\"\n}\n", $this->file(), 'every key kept, the unknown one last');

        // A comma string, a number as text, unknown keys in their order: none is dropped.
        file_put_contents($this->dir . '/data/items/r1.json', '{"zeta":1,"tags":"a, b","_note":"n","count":"2.5","uuid":"r1","alpha":null,"title":"T","flag":"x"}');
        $dm = $this->dm();
        $dm->saveType($dm->findType('item', 'r1') ?? $this->fail('hand-written file read'));

        $saved = json_decode($this->file(), true);
        $this->assertSame(['title', 'count', 'tags', 'uuid', '_note', '_version', 'zeta', 'alpha', 'flag'], array_keys($saved));
        $this->assertSame(['a', 'b'], $saved['tags']);
        $this->assertSame(2.5, $saved['count']);
        $this->assertSame([1, null, 'x'], [$saved['zeta'], $saved['alpha'], $saved['flag']]);
    }

    public function test_a_value_that_does_not_convert_stays_as_it_is(): void
    {
        mkdir($this->dir . '/data/items', 0755, true);
        file_put_contents($this->dir . '/data/items/r1.json', '{"uuid":"r1","title":"T","count":"many","tags":"[broken"}');
        $dm = $this->dm();

        $dm->saveType($dm->findType('item', 'r1') ?? $this->fail('hand-written file read'), false);

        $saved = json_decode($this->file(), true);
        $this->assertSame('many', $saved['count']);
        $this->assertSame('[broken', $saved['tags']);
    }

    public function test_unicode_stays_readable_in_the_file(): void
    {
        $dm = $this->dm();
        $dm->saveType($this->item($dm, ['uuid' => 'r1', 'title' => 'Grüße a/b', 'tags' => ['Übersetzung']]));

        $this->assertStringContainsString('"Grüße a/b"', $this->file());
        $this->assertStringContainsString('"Übersetzung"', $this->file());
        $this->assertStringNotContainsString('\\u00', $this->file(), 'no escaped umlauts');
    }

    public function test_sql_storage_still_receives_tags_as_json_text(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, count REAL, tags TEXT, _version INTEGER)');
        $dm = $this->dm(new SqliteDriver($pdo));

        $dm->saveType($this->item($dm, ['uuid' => 'r1', 'title' => 'T', 'count' => '2', 'tags' => ['a']]));
        $dm->saveType($this->item($dm, ['uuid' => 'r2', 'title' => 'T', 'tags' => '["a"]']));

        $this->assertSame('["a"]', $pdo->query("SELECT tags FROM items WHERE uuid = 'r1'")->fetchColumn());
        $this->assertSame('["a"]', $pdo->query("SELECT tags FROM items WHERE uuid = 'r2'")->fetchColumn());
    }

    /**
     * A class-backed save names only the class's fields and id; the file's other keys (a property
     * removed from the class, a key added by hand) are kept and come last, in their stored order.
     */
    public function test_a_class_backed_save_writes_the_canonical_order_and_keeps_other_keys_last(): void
    {
        require_once __DIR__ . '/Fixtures/ModelBridge/TaggedReference.php';
        mkdir($this->dir . '/data/reference', 0755, true);
        $file = $this->dir . '/data/reference/r1.json';
        file_put_contents($file, '{"old":"gone","uses":["a"],"extra":1,"uuid":"r1","name":"N"}');
        $types = new TypeRegistry($this->dir . '/models', new \Preflow\Data\Locales(['en'], 'en'), ['reference' => \Preflow\Data\Tests\Fixtures\ModelBridge\TaggedReference::class]);
        $dm = new DataManager(['default' => new JsonFileDriver($this->dir . '/data')], 'default', $types);
        $dm->fieldKinds()->list('tags');

        $ref = new \Preflow\Data\Tests\Fixtures\ModelBridge\TaggedReference();
        $ref->uuid = 'r1';
        $ref->name = 'N';
        $ref->uses = ['a'];
        $dm->save($ref, false);

        $canonical = "{\n    \"name\": \"N\",\n    \"uses\": [\n        \"a\"\n    ],\n    \"uuid\": \"r1\",\n    \"old\": \"gone\",\n    \"extra\": 1\n}\n";
        $this->assertSame($canonical, (string) file_get_contents($file), 'the type\'s keys in order, then the other keys in their stored order');

        $dm->save($ref, false);
        $this->assertSame($canonical, (string) file_get_contents($file), 'saving again gives the same bytes');
    }
}
