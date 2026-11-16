<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

require_once __DIR__ . '/StatementLog.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\Schema\TextRuleConversion;
use Preflow\Data\Text\TextMatch;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * `preflow migrate` gives the record tables SQLite made before 0.78 the text rule (data README,
 * "Exact comparisons"): text fields' columns become preflow_text in one table rebuild, a unique
 * column whose values would collide is left exact and its duplicates listed, nothing is changed
 * or removed in a row, and the indexes are rebuilt when the rule's version changes.
 */
final class TextRuleSyncTest extends TestCase
{
    private string $dir;
    private StatementLog $pdo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/textrule_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->pdo = new StatementLog('sqlite:' . $this->dir . '/test.sqlite');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_an_old_table_is_converted_once(): void
    {
        $this->oldPeople();
        $this->pdo->exec("INSERT INTO people (uuid, title, body, d) VALUES ('r1', 'Müller', 'Text', '2026-01-02')");

        $lines = $this->sync()->sync(self::people());

        $this->assertSame(['converted collations of people: title, body'], $lines);
        $this->assertSame(
            ['uuid' => 'BINARY', 'title' => 'preflow_text', 'body' => 'preflow_text', 'd' => 'BINARY'],
            Grammars::for($this->pdo)->columnCollations($this->pdo, 'people'),
        );
        $this->assertSame([['uuid' => 'r1', 'title' => 'Müller', 'body' => 'Text', 'd' => '2026-01-02']], $this->rows());
        $this->assertContains('uniq_people_title', Grammars::for($this->pdo)->uniqueIndexes($this->pdo, 'people'));

        $this->pdo->clear();
        $this->assertSame([], $this->sync()->sync(self::people()), 'converted once');
        $this->assertSame([], $this->pdo->matching('/^\s*(CREATE TABLE|ALTER|DROP|INSERT)/i'), 'no rebuild the second time');
    }

    public function test_duplicates_under_the_rule_leave_the_column_and_are_listed(): void
    {
        $this->oldPeople();
        $this->pdo->exec("INSERT INTO people (uuid, title, body) VALUES ('r1', 'Müller', 'eins'), ('r2', 'MÜLLER', 'zwei'), ('r3', 'Muller', 'drei')");
        $before = $this->rows();

        $lines = $this->sync()->sync(self::people());

        $this->assertSame([
            'refused: people.title: 1 value is there more than once when case is ignored; the column keeps comparing exactly until they differ',
            '  "Müller" in r1, r2',
            'converted collations of people: body',
        ], $lines);
        $collations = Grammars::for($this->pdo)->columnCollations($this->pdo, 'people');
        $this->assertSame('BINARY', $collations['title'], 'the column stays exact');
        $this->assertSame('preflow_text', $collations['body']);
        $this->assertSame($before, $this->rows(), 'no row changed or removed, byte for byte');

        $this->pdo->exec("UPDATE people SET title = 'Müller 2' WHERE uuid = 'r2'");
        $this->assertSame(['converted collations of people: title'], $this->sync()->sync(self::people()), 'converted once they differ');
        $this->assertSame('preflow_text', Grammars::for($this->pdo)->columnCollations($this->pdo, 'people')['title']);
    }

    public function test_a_scoped_unique_counts_duplicates_within_its_scope(): void
    {
        $this->pdo->exec('CREATE TABLE code (uuid TEXT PRIMARY KEY, title TEXT, purpose TEXT)');
        $this->pdo->exec('CREATE UNIQUE INDEX uniq_code_title ON code (title, purpose)');
        $this->pdo->exec('CREATE INDEX idx_code_purpose ON code (purpose)');
        $this->pdo->exec("INSERT INTO code (uuid, title, purpose) VALUES ('r1', 'Müller', 'a'), ('r2', 'MÜLLER', 'b')");

        $lines = $this->sync()->sync(self::code());

        $this->assertSame(['converted collations of code: title, purpose'], $lines);
        $this->assertSame(['uuid' => 'BINARY', 'title' => 'preflow_text', 'purpose' => 'preflow_text'], Grammars::for($this->pdo)->columnCollations($this->pdo, 'code'));
    }

    public function test_many_duplicate_groups_are_listed_up_to_twenty(): void
    {
        $this->oldPeople();
        for ($i = 1; $i <= 22; $i++) {
            $this->pdo->exec("INSERT INTO people (uuid, title) VALUES ('a{$i}', 'name{$i}'), ('b{$i}', 'NAME{$i}')");
        }

        $lines = $this->sync()->sync(self::people());

        $this->assertSame('refused: people.title: 22 values are there more than once when case is ignored; the column keeps comparing exactly until they differ', $lines[0]);
        $this->assertCount(20, array_filter($lines, static fn (string $l): bool => str_starts_with($l, '  "')));
        $this->assertContains('  … and 2 more', $lines);
    }

    public function test_a_changed_icu_version_reindexes(): void
    {
        $this->sync()->sync(self::people());
        $this->pdo->exec('CREATE TABLE preflow_text_collation (icu TEXT)');
        $this->pdo->exec("INSERT INTO preflow_text_collation (icu) VALUES ('1.0')");
        $this->pdo->clear();

        $lines = $this->conversion()->convert();

        $this->assertSame(['rebuilt the indexes over text columns for ' . TextMatch::version() . ' (was 1.0)'], $lines);
        $this->assertNotSame([], $this->pdo->matching('/^REINDEX\b/i'));
        $this->assertSame([TextMatch::version()], $this->pdo->query('SELECT icu FROM preflow_text_collation')->fetchAll(\PDO::FETCH_COLUMN));

        $this->pdo->clear();
        $this->assertSame([], $this->conversion()->convert(), 'the same version: nothing to do');
        $this->assertSame([], $this->pdo->matching('/^REINDEX\b/i'));
    }

    public function test_a_first_run_records_the_version_quietly(): void
    {
        $this->sync()->sync(self::people());

        $this->assertSame([], $this->conversion()->convert());
        $this->assertSame([TextMatch::version()], $this->pdo->query('SELECT icu FROM preflow_text_collation')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * A version under which two stored values become equal: their unique index cannot be rebuilt.
     * It is left as it was, the values are listed and migrate refuses; the version is recorded
     * only once every index is rebuilt.
     */
    public function test_a_reindex_that_finds_duplicates_lists_them(): void
    {
        $this->sync()->sync(self::people());
        // Written while the collation compared by bytes (an older rule): both values are in the unique index.
        $old = new \PDO('sqlite:' . $this->dir . '/test.sqlite');
        $old->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        set_error_handler(static fn (int $level): bool => $level === E_DEPRECATED, E_DEPRECATED);
        try {
            $old->sqliteCreateCollation(SqliteDialect::TEXT_COLLATION, 'strcmp');
        } finally {
            restore_error_handler();
        }
        $old->exec("INSERT INTO people (uuid, title) VALUES ('r1', 'Müller'), ('r2', 'MÜLLER')");
        $old->exec("CREATE TABLE preflow_text_collation (icu TEXT)");
        $old->exec("INSERT INTO preflow_text_collation (icu) VALUES ('0.1')");
        unset($old);

        $lines = $this->conversion()->convert();

        $this->assertSame([
            'refused: people.title: 1 value is there more than once when case is ignored; its unique index keeps the order of 0.1 until they differ',
            '  "Müller" in r1, r2',
        ], $lines);
        $this->assertSame(['0.1'], $this->pdo->query('SELECT icu FROM preflow_text_collation')->fetchAll(\PDO::FETCH_COLUMN), 'not recorded');
        $this->assertCount(2, $this->rows());
    }

    public function test_a_conversion_refused_by_the_database_changes_nothing(): void
    {
        $this->oldPeople();
        $this->pdo->exec("INSERT INTO people (uuid, title) VALUES ('r1', 'Müller')");
        // In the rebuild's way: making its new table fails, and the rebuild rolls back.
        $this->pdo->exec('CREATE TABLE people__preflow_converting (x TEXT)');

        $lines = $this->sync()->sync(self::people());

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('refused: people: the server refused the conversion (', $lines[0]);
        $this->assertStringEndsWith('); run migrate again', $lines[0]);
        $this->assertSame('BINARY', Grammars::for($this->pdo)->columnCollations($this->pdo, 'people')['title']);
        $this->assertSame([['uuid' => 'r1', 'title' => 'Müller', 'body' => null, 'd' => null]], $this->rows());
    }

    public function test_tables_made_by_migrations_are_left_alone(): void
    {
        $this->pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, title VARCHAR(255) NOT NULL)');
        $this->pdo->clear();

        $this->assertSame([], $this->conversion()->convert());
        $this->assertSame('BINARY', Grammars::for($this->pdo)->columnCollations($this->pdo, 'notes')['title']);
        $this->assertSame([], $this->pdo->matching('/^\s*(ALTER|CREATE|DROP|INSERT|REINDEX)\b.*notes/is'));
    }

    /** The table as a site made it before 0.78: text columns compared by bytes. */
    private function oldPeople(): void
    {
        $this->pdo->exec('CREATE TABLE people (uuid TEXT PRIMARY KEY, title TEXT, body TEXT, d TEXT)');
        $this->pdo->exec('CREATE UNIQUE INDEX uniq_people_title ON people (title)');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->pdo->query('SELECT * FROM people ORDER BY uuid COLLATE BINARY')->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function people(): TypeDefinition
    {
        return new TypeDefinition('people', 'people', 'sqlite', [
            'title' => new TypeFieldDefinition('title', unique: true),
            'body' => new TypeFieldDefinition('body', 'text'),
            'd' => new TypeFieldDefinition('d', 'date'),
        ]);
    }

    private static function code(): TypeDefinition
    {
        return new TypeDefinition('code', 'code', 'sqlite', [
            'title' => new TypeFieldDefinition('title', unique: ['purpose']),
            'purpose' => new TypeFieldDefinition('purpose'),
        ]);
    }

    private function dm(): DataManager
    {
        return new DataManager(drivers: ['sqlite' => new SqliteDriver($this->pdo)], defaultDriver: 'sqlite');
    }

    private function sync(): RecordTableSync
    {
        return new RecordTableSync($this->dm());
    }

    private function conversion(): TextRuleConversion
    {
        $tables = new RecordTables();
        $tables->add('test', static fn (): array => [RecordTables::entry(self::people())]);

        return new TextRuleConversion($this->dm(), $tables);
    }
}
