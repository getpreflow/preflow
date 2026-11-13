<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\Schema;
use Preflow\Data\TypeRegistry;
use Preflow\Publishing\NoRecordChecks;
use Preflow\Publishing\NoRecordColumns;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\PublishingTables;
use Preflow\Publishing\SchemaPublishingTypes;
use Preflow\Testing\MysqlTestDatabase;

/** The tables of a publishing type on MySQL/MariaDB: made once, then publish and revisions work on them. */
#[Group('mysql')]
final class PublishingTablesMysqlTest extends TestCase
{
    private \PDO $pdo;
    private string $dir;
    private DataManager $dm;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
        $this->dir = sys_get_temp_dir() . '/publishing_mysql_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/article.json', (string) json_encode([
            'key' => 'article', 'table' => 'article', 'storage' => 'mysql', 'publishing' => true,
            'fields' => ['title' => ['type' => 'string'], 'views' => ['type' => 'number']],
        ]));
        $this->dm = new DataManager(['mysql' => new MysqlDriver($this->pdo)], 'mysql', new TypeRegistry($this->dir));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
        if (isset($this->dir)) {
            @unlink($this->dir . '/article.json');
            @rmdir($this->dir);
        }
    }

    public function test_the_tables_are_made_once_and_carry_publish_and_revisions(): void
    {
        $tables = new PublishingTables($this->dm, new SchemaPublishingTypes($this->dm), new NoRecordColumns());

        $first = $tables->sync();

        $schema = new Schema($this->pdo);
        foreach (['article', 'article__published', 'article__revisions'] as $table) {
            $this->assertTrue($schema->hasTable($table), $table);
        }
        $this->assertContains('created table article__published', $first);
        $this->assertArrayHasKey('_version', $schema->columnTypes('article'));
        $this->assertArrayHasKey('_published_by', $schema->columnTypes('article__published'));
        $this->assertSame([], $tables->sync(), 'a second run changes nothing');

        $p = new Publisher($this->dm, new SchemaPublishingTypes($this->dm), new NoRecordChecks());
        $p->saveDraft(DynamicRecord::fromArray($this->dm->getTypeRegistry()->get('article'), ['uuid' => 'a1', 'title' => 'One', 'views' => 3]), null);
        $p->publish($this->stored('a1'), 'ana');
        $again = $this->stored('a1');
        $again->set('title', 'Two');
        $p->publish($again, 'ana');

        $this->assertSame('Two', $p->live('article', 'a1')?->get('title'));
        $this->assertSame([2, 1], array_map(static fn ($r): int => $r->seq, $p->revisions('article', 'a1')));
        $this->assertSame(['title'], $p->revisions('article', 'a1')[0]->changed);
    }

    /** Declared indexes reach the existing working and published tables (a LONGTEXT column by a prefix); the revisions get none. */
    public function test_declared_indexes_reach_the_existing_published_table(): void
    {
        (new PublishingTables($this->dm, new SchemaPublishingTypes($this->dm), new NoRecordColumns()))->sync();
        file_put_contents($this->dir . '/article.json', (string) json_encode([
            'key' => 'article', 'table' => 'article', 'storage' => 'mysql', 'publishing' => true,
            'fields' => ['title' => ['type' => 'string', 'index' => true], 'views' => ['type' => 'number']],
            'indexes' => [['title', 'views']],
        ]));
        $dm = new DataManager(['mysql' => new MysqlDriver($this->pdo)], 'mysql', new TypeRegistry($this->dir));
        $tables = new PublishingTables($dm, new SchemaPublishingTypes($dm), new NoRecordColumns());

        $lines = $tables->sync();

        $schema = new Schema($this->pdo);
        foreach (['article', 'article__published'] as $table) {
            $this->assertContains("added index idx_{$table}_title", $lines);
            $this->assertContains("added index idx_{$table}_title_views", $lines);
            $this->assertEqualsCanonicalizing(['PRIMARY', "idx_{$table}_title", "idx_{$table}_title_views"], $schema->indexes($table), $table);
        }
        $this->assertSame(['PRIMARY'], $schema->indexes('article__revisions'));
        $this->assertSame([], array_values(array_filter($tables->sync(), static fn (string $l): bool => !str_starts_with($l, 'warning: '))), 'a second run adds nothing');
    }

    private function stored(string $id): DynamicRecord
    {
        return $this->dm->findType('article', $id) ?? throw new \RuntimeException("no article {$id}");
    }
}
