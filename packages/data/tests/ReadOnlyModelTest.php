<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Tests\Fixtures\ModelBridge\Vacancy;
use Preflow\Data\TypeRegistry;

/** findAs()/queryAs(): a model class loaded from another table of its shape, read-only. */
final class ReadOnlyModelTest extends TestCase
{
    private DataManager $dm;

    private string $dir;

    public static function setUpBeforeClass(): void
    {
        // The monorepo's root autoloader does not map this package's test namespace.
        require_once __DIR__ . '/Fixtures/ModelBridge/Vacancy.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/read_only_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (['vacancy', 'vacancy__published'] as $table) {
            $pdo->exec("CREATE TABLE {$table} (uuid TEXT PRIMARY KEY, title TEXT, company TEXT, salary_from INTEGER, open INTEGER, code TEXT UNIQUE)");
        }
        $types = new TypeRegistry($this->dir, new Locales(['en', 'de'], 'en'), ['vacancy' => Vacancy::class]);
        $this->dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default', $types);

        $type = $types->get('vacancy');
        $this->dm->saveType(DynamicRecord::fromArray($type, ['uuid' => 'a1', 'title' => ['en' => 'Draft'], 'company' => 'c1']));
        $published = new \Preflow\Data\TypeDefinition('vacancy', 'vacancy__published', 'default', $type->fields, locales: $type->locales);
        $this->dm->saveType(DynamicRecord::fromArray($published, ['uuid' => 'a1', 'title' => ['en' => 'Carpenter', 'de' => 'Schreiner'], 'company' => 'c1', 'open' => 1]));
    }

    protected function tearDown(): void
    {
        rmdir($this->dir);
    }

    public function test_an_object_loaded_from_another_table_cannot_be_saved(): void
    {
        $job = $this->dm->findAs(Vacancy::class, 'a1', 'vacancy__published');

        $this->assertInstanceOf(Vacancy::class, $job);
        $this->assertSame(['en' => 'Carpenter', 'de' => 'Schreiner'], $job->title, 'read as the type reads it');
        $this->assertTrue($job->open);
        $this->assertTrue($this->dm->isReadOnly($job));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('was loaded read-only (from table vacancy__published) and cannot be saved');
        $this->dm->save($job);
    }

    public function test_no_write_takes_a_read_only_object(): void
    {
        $job = $this->dm->findAs(Vacancy::class, 'a1', 'vacancy__published');
        foreach (['insert', 'update', 'delete'] as $write) {
            try {
                $this->dm->{$write}($job);
                $this->fail("{$write}() took a read-only object");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('cannot be saved', $e->getMessage());
            }
        }
        $this->assertSame(['en' => 'Draft'], $this->dm->find(Vacancy::class, 'a1')?->title, 'the draft is untouched');
        $this->assertNotNull($this->dm->findAs(Vacancy::class, 'a1', 'vacancy__published'));
    }

    public function test_a_query_of_another_table_gives_read_only_objects(): void
    {
        $items = $this->dm->queryAs(Vacancy::class, 'vacancy__published')->locale('de')->where('title', 'Schreiner')->get()->items();

        $this->assertCount(1, $items);
        $this->assertInstanceOf(Vacancy::class, $items[0]);
        $this->assertTrue($this->dm->isReadOnly($items[0]));
        $this->assertNull($this->dm->findAs(Vacancy::class, 'missing', 'vacancy__published'));
    }

    public function test_objects_of_the_own_table_stay_writable(): void
    {
        $draft = $this->dm->find(Vacancy::class, 'a1');
        $listed = $this->dm->query(Vacancy::class)->first();

        $this->assertFalse($this->dm->isReadOnly($draft));
        $this->assertFalse($this->dm->isReadOnly($listed));
        $draft->title = ['en' => 'Carpenter'];
        $this->dm->save($draft, validate: false);
        $this->assertSame(['en' => 'Carpenter'], $this->dm->find(Vacancy::class, 'a1')?->title);
    }
}
