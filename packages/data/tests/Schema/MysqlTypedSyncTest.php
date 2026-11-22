<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
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
            'slug' => 'varchar(191)', 'city' => 'varchar(191)', 'short' => 'varchar(40)', 'name' => 'longtext', '_updated_at' => 'varchar(32)',
        ], (new Schema($this->pdo))->columnTypes('event'));
        $this->assertContains('uniq_event_slug', (new Schema($this->pdo))->indexes('event'));
        $text = (new MysqlGrammar())->on($this->pdo)->textCollation() ?? 'utf8mb4_bin';
        $this->assertSame($text, $this->collation('event', 'slug'), 'a slug is text: it compares by the rule (exactly on a server without the collation)');

        $driver = new MysqlDriver($this->pdo);
        $driver->save('event', 'a', ['uuid' => 'a', 'slug' => 'cafe', 'seats' => '12']);
        $driver->save('event', 'b', ['uuid' => 'b', 'slug' => 'kafe', 'seats' => '9']);
        $driver->save('event', 'c', ['uuid' => 'c', 'slug' => 'café', 'seats' => '12.5']);
        $driver->save('event', 'd', ['uuid' => 'd', 'slug' => null]);
        $driver->save('event', 'e', ['uuid' => 'e', 'slug' => null]);
        $this->assertSame('12', (string) $driver->findOne('event', 'a')['seats']);
        $sorted = array_column($driver->findMany('event', (new Query())->orderBy('seats'))->items(), 'uuid');
        $this->assertSame(['b', 'a', 'c'], array_values(array_diff($sorted, ['d', 'e'])), 'numbers sort numerically');

        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO event (uuid, slug) VALUES ('f', 'cafe')");
    }

    /** Text fields' columns take the rule's collation, the rest utf8mb4_bin: ids, machine values, dates, other free text. */
    public function test_a_new_table_gets_text_and_exact_columns(): void
    {
        $text = (new MysqlGrammar())->on($this->pdo)->textCollation();
        if ($text === null) {
            $this->markTestSkipped('The server has none of MysqlDialect::MATCH_COLLATIONS.');
        }
        $def = new TypeDefinition('person', 'person', 'mysql', [
            'name' => new TypeFieldDefinition('name', unique: true),
            'bio' => new TypeFieldDefinition('bio', 'text'),
            'key' => new TypeFieldDefinition('key', exact: true),
            'born' => new TypeFieldDefinition('born', 'date'),
            'age' => new TypeFieldDefinition('age', 'number'),
            'title' => new TypeFieldDefinition('title', translatable: true),
            'meta' => new TypeFieldDefinition('meta', 'json'),
        ]);
        $this->sync()->sync($def);

        $this->assertSame(
            ['uuid' => 'utf8mb4_bin', 'name' => $text, 'bio' => $text, 'key' => 'utf8mb4_bin', 'born' => 'utf8mb4_bin', 'age' => null, 'title' => $text, 'meta' => 'utf8mb4_bin', '_updated_at' => 'utf8mb4_bin'],
            Grammars::for($this->pdo)->columnCollations($this->pdo, 'person'),
        );
        $stmt = $this->pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'person'");
        $this->assertSame($text, $stmt->fetchColumn(), 'the table default is the rule\'s collation');
        $driver = new MysqlDriver($this->pdo);
        $driver->save('person', 'a', ['uuid' => 'a', 'name' => 'MÜLLER']);
        $driver->save('person', 'b', ['uuid' => 'b', 'name' => 'Muller']);
        $this->expectException(\Preflow\Data\UniqueViolation::class);
        $driver->save('person', 'c', ['uuid' => 'c', 'name' => 'Müller']);
    }

    public function test_a_text_column_is_not_reported_as_drifted(): void
    {
        $sync = $this->sync();
        $sync->sync(self::def());

        $this->assertSame([], $sync->sync(self::def()));
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
            'warning: event.seats is longtext, expected number; convert the table (see the data README)',
            'warning: event.open is longtext, expected boolean; convert the table (see the data README)',
            'warning: event.starts is longtext, expected date; convert the table (see the data README)',
            'warning: event.slug is longtext, expected short; convert the table (see the data README)',
            'warning: event.city is longtext, expected short; convert the table (see the data README)',
            'warning: event.short is longtext, expected short; convert the table (see the data README)',
            'warning: event._updated_at is longtext, expected date; convert the table (see the data README)',
            'warning: event.slug has no unique index; convert the table (see the data README)',
            'added index idx_event_city',
            'added index idx_event_short',
        ], $lines, 'plain indexes on the LONGTEXT columns by a prefix; no unique index over a column of another type');
        $this->assertSame($before, (new Schema($this->pdo))->columnTypes('event'));
        $this->assertEqualsCanonicalizing(['PRIMARY', 'idx_event_city', 'idx_event_short'], (new Schema($this->pdo))->indexes('event'));
        $this->assertSame(array_slice($lines, 0, 8), $sync->sync(self::def()), 'nothing changed, so the same warnings again');
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
        $this->assertSame((new MysqlGrammar())->on($this->pdo)->textCollation() ?? 'utf8mb4_bin', $this->collation('event', 'slug'), 'a text field\'s column, added later too');
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
