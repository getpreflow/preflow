<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Data\ChangeDateBackfill;
use Preflow\Data\ChangeDates;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\ContextFactory;

/**
 * ChangeDateBackfill (`preflow migrate`'s data.change-dates, 0.84.0) beyond the storage contract:
 * a JSON file's time, a published copy's time, a type's own updated field, and a SQL table its sync
 * has not given the column yet.
 */
final class ChangeDateBackfillTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_backfill_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param list<TypeDefinition> $defs */
    private static function backfill(DataManager $dm, array $defs): array
    {
        return Contexts::run(
            ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable('2026-11-22T12:00:00Z'))),
            static fn (): array => (new ChangeDateBackfill($dm, static fn (): array => $defs))->run(),
        );
    }

    public function test_a_json_record_gets_its_files_time_and_a_published_copy_its_publish_time(): void
    {
        $driver = new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], 'default');
        $def = new TypeDefinition('page', 'page', 'default', ['title' => new TypeFieldDefinition('title')]);
        $copy = new TypeDefinition('page__published', 'page__published', 'default', ['title' => new TypeFieldDefinition('title')]);
        $driver->save('page', 'a', ['uuid' => 'a', 'title' => 'A']);
        touch($this->dir . '/data/page/a.json', (new \DateTimeImmutable('2024-04-04T04:04:04Z'))->getTimestamp());
        $driver->save('page__published', 'a', ['uuid' => 'a', 'title' => 'A', '_published_at' => '2025-05-05T07:05:05+02:00']);

        $lines = self::backfill($dm, [$def, $copy]);

        $this->assertSame([
            'gave 1 record of page a change date (1 from the file time)',
            'gave 1 record of page__published a change date (1 from the publish time)',
        ], $lines);
        $this->assertSame('2024-04-04T04:04:04+00:00', $driver->findOne('page', 'a')[ChangeDates::FIELD]);
        $this->assertSame('2025-05-05T05:05:05+00:00', $driver->findOne('page__published', 'a')[ChangeDates::FIELD]);
    }

    public function test_a_types_own_updated_field_keeps_its_values_and_fills_the_empty_ones(): void
    {
        $driver = new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], 'default');
        $def = new TypeDefinition('memo', 'memo', 'default', ['title' => new TypeFieldDefinition('title'), 'updated_at' => new TypeFieldDefinition('updated_at', 'date')]);
        $driver->save('memo', 'kept', ['uuid' => 'kept', 'title' => 'K', 'updated_at' => '2026-01-02 03:04:05']);
        $driver->save('memo', 'empty', ['uuid' => 'empty', 'title' => 'E', 'updated_at' => '']);
        touch($this->dir . '/data/memo/empty.json', (new \DateTimeImmutable('2023-03-03T03:03:03Z'))->getTimestamp());

        $this->assertSame(['gave 1 record of memo a change date (1 from the file time)'], self::backfill($dm, [$def]));

        $this->assertSame('2026-01-02 03:04:05', $driver->findOne('memo', 'kept')['updated_at'], 'as the site wrote it');
        $this->assertSame('2023-03-03T03:03:03+00:00', $driver->findOne('memo', 'empty')['updated_at']);
        $this->assertArrayNotHasKey(ChangeDates::FIELD, $driver->findOne('memo', 'kept'), 'no second key');
    }

    public function test_a_sql_table_without_the_column_is_left_out(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE page (uuid TEXT PRIMARY KEY, title TEXT)");
        $pdo->exec("INSERT INTO page (uuid, title) VALUES ('a', 'A')");
        $dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default');
        $missing = new TypeDefinition('gone', 'gone', 'default', ['title' => new TypeFieldDefinition('title')]);

        $this->assertSame([], self::backfill($dm, [new TypeDefinition('page', 'page', 'default', ['title' => new TypeFieldDefinition('title')]), $missing]));
        $this->assertSame([['uuid' => 'a', 'title' => 'A']], $pdo->query('SELECT * FROM page')->fetchAll(\PDO::FETCH_ASSOC));
    }
}
