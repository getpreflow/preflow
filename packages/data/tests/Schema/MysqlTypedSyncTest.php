<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Query;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\MysqlTestDatabase;

/** Record tables on MySQL/MariaDB: typed when created, never altered after, drift reported. */
#[Group('mysql')]
final class MysqlTypedSyncTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }

    public function testNewTableIsTyped(): void
    {
        $lines = $this->sync()->sync(self::def());

        $this->assertSame(['created table event', 'added unique index uniq_event_slug', 'added index idx_event_city', 'added index idx_event_short'], $lines);
        $this->assertSame([
            'uuid' => 'varchar(191)', 'seats' => 'double', 'open' => 'tinyint(1)', 'starts' => 'varchar(32)',
            'slug' => 'varchar(191)', 'city' => 'varchar(191)', 'short' => 'varchar(40)', 'name' => 'longtext',
        ], (new Schema($this->pdo))->columnTypes('event'));
        $this->assertContains('uniq_event_slug', (new Schema($this->pdo))->indexes('event'));
        $this->assertSame('utf8mb4_bin', $this->collation('event', 'slug'), 'unique values compare exactly');

        $driver = new MysqlDriver($this->pdo);
        $driver->save('event', 'a', ['uuid' => 'a', 'slug' => 'cafe', 'seats' => '12']);
        $driver->save('event', 'b', ['uuid' => 'b', 'slug' => 'Cafe', 'seats' => '9']);
        $driver->save('event', 'c', ['uuid' => 'c', 'slug' => 'café', 'seats' => '12.5']);
        $driver->save('event', 'd', ['uuid' => 'd', 'slug' => null]);
        $driver->save('event', 'e', ['uuid' => 'e', 'slug' => null]);
        $this->assertSame('12', (string) $driver->findOne('event', 'a')['seats']);
        $sorted = array_column($driver->findMany('event', (new Query())->orderBy('seats'))->items(), 'uuid');
        $this->assertSame(['b', 'a', 'c'], array_values(array_diff($sorted, ['d', 'e'])), 'numbers sort numerically');

        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO event (uuid, slug) VALUES ('f', 'cafe')");
    }

    public function testScopedUniqueIndexCoversItsScope(): void
    {
        $def = new TypeDefinition('code', 'code', 'mysql', [
            'code' => new TypeFieldDefinition('code', unique: ['purpose']),
            'purpose' => new TypeFieldDefinition('purpose'),
        ]);
        $this->sync()->sync($def);

        $this->assertSame('varchar(191)', (new Schema($this->pdo))->columnTypes('code')['purpose'], 'a scope column is indexable');
        $this->pdo->exec("INSERT INTO code (uuid, code, purpose) VALUES ('a', 'X1', 'invite'), ('b', 'X1', 'reset')");
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO code (uuid, code, purpose) VALUES ('c', 'X1', 'invite')");
    }

    public function testExistingColumnIsNeverAltered(): void
    {
        $sync = $this->sync();
        $sync->sync(self::def(), typed: false);
        $before = (new Schema($this->pdo))->columnTypes('event');

        $lines = $sync->sync(self::def());

        $this->assertSame([
            'warning: event.seats is longtext, expected number; run preflow data:convert',
            'warning: event.open is longtext, expected boolean; run preflow data:convert',
            'warning: event.starts is longtext, expected date; run preflow data:convert',
            'warning: event.slug is longtext, expected short; run preflow data:convert',
            'warning: event.city is longtext, expected short; run preflow data:convert',
            'warning: event.short is longtext, expected short; run preflow data:convert',
            'warning: event.slug has no unique index; run preflow data:convert',
        ], $lines);
        $this->assertSame($before, (new Schema($this->pdo))->columnTypes('event'));
        $this->assertSame(['PRIMARY'], (new Schema($this->pdo))->indexes('event'));
    }

    public function testNewColumnOnExistingTableIsTyped(): void
    {
        $sync = $this->sync();
        $sync->sync(new TypeDefinition('event', 'event', 'mysql', ['name' => new TypeFieldDefinition('name')]));

        $lines = $sync->sync(self::def());

        $this->assertContains('added column event.seats', $lines);
        $this->assertContains('added unique index uniq_event_slug', $lines);
        $types = (new Schema($this->pdo))->columnTypes('event');
        $this->assertSame('double', $types['seats']);
        $this->assertSame('varchar(191)', $types['slug']);
        $this->assertSame('utf8mb4_bin', $this->collation('event', 'slug'));
        $this->assertSame([], $sync->sync(self::def()));
    }

    /** A long table and field name still give a valid index name (MySQL allows 64 characters). */
    public function testLongIndexNameIsShortened(): void
    {
        $table = str_repeat('t', 50);
        $def = new TypeDefinition($table, $table, 'mysql', [str_repeat('f', 20) => new TypeFieldDefinition(str_repeat('f', 20), unique: true)]);

        $lines = $this->sync()->sync($def);

        $this->assertCount(2, $lines);
        $this->assertSame(64, strlen(substr($lines[1], strlen('added unique index '))));
    }

    private function collation(string $table, string $column): string
    {
        $stmt = $this->pdo->prepare('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);

        return (string) $stmt->fetchColumn();
    }

    private static function def(): TypeDefinition
    {
        return new TypeDefinition('event', 'event', 'mysql', [
            'seats' => new TypeFieldDefinition('seats', 'number'),
            'open' => new TypeFieldDefinition('open', 'boolean'),
            'starts' => new TypeFieldDefinition('starts', 'date'),
            'slug' => new TypeFieldDefinition('slug', unique: true),
            'city' => new TypeFieldDefinition('city', index: true),
            'short' => new TypeFieldDefinition('short', index: true, length: 40),
            'name' => new TypeFieldDefinition('name'),
        ]);
    }

    private function sync(): RecordTableSync
    {
        return new RecordTableSync(new DataManager(drivers: ['mysql' => new MysqlDriver($this->pdo)], defaultDriver: 'mysql'));
    }
}
