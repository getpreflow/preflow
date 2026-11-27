<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Values;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
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
 * A "tags" field is a list in the core (0.93), as Folio has made it since 0.78: CoreValues
 * registers it, so a site without Folio stores a list (a comma string or JSON text given becomes
 * one; JSON text in an SQL column) and reads one out, on SQL and JSON storage alike.
 */
final class TagsWithoutFolioTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tags_core_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        file_put_contents($this->dir . '/models/post.json', json_encode([
            'key' => 'post', 'table' => 'posts', 'storage' => 'default',
            'fields' => ['title' => ['type' => 'string'], 'tags' => ['type' => 'tags']],
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

    public function test_tags_is_a_list_without_folio(): void
    {
        $kinds = new FieldKinds();
        CoreValues::register($kinds);

        $this->assertTrue($kinds->isList('tags'));
        $this->assertTrue($kinds->holdsList(new TypeFieldDefinition('tags', 'tags')));
        $this->assertFalse($kinds->isText('tags'), 'not free text: no text index or text matching on its column');
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'json' => ['json'];
    }

    #[DataProvider('storages')]
    public function test_a_tags_value_is_saved_and_read_back_as_a_list(string $storage): void
    {
        $types = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'));
        $driver = $storage === 'sqlite' ? new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/db.sqlite')) : new JsonFileDriver($this->dir . '/store');
        $dm = new DataManager(['default' => $driver], 'default', $types);
        $def = $types->get('post');
        if ($storage === 'sqlite') {
            (new RecordTableSync($dm))->sync($def);
        }

        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'One', 'tags' => 'news, events']), false);
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'b', 'title' => 'Two', 'tags' => '["Übersetzung"]']), false);

        // Stored as a list: a list in a JSON file, its JSON text in an SQL column.
        $stored = static fn (string $id): mixed => $dm->findType('post', $id)?->get('tags');
        $this->assertSame($storage === 'sqlite' ? '["news","events"]' : ['news', 'events'], $stored('a'), 'a comma string is stored as a list');
        $this->assertSame($storage === 'sqlite' ? '["Übersetzung"]' : ['Übersetzung'], $stored('b'), 'JSON text is stored as its list');
        // Read out as a list on both (the API's read value).
        $field = $def->fields['tags'];
        $value = $dm->fieldKinds()->valueOf($field);
        $this->assertSame(['news', 'events'], $value->toOutput($stored('a'), $field));
        $this->assertSame(['Übersetzung'], $value->toOutput($stored('b'), $field));
    }
}
