<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

require_once __DIR__ . '/StatementLog.php';
require_once __DIR__ . '/RuleOnlyGrammar.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\MigrationColumns;
use Preflow\Data\Migration\Migrator;
use Preflow\Data\Schema\DeclaredTables;
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
        SqliteDialect::prepare($this->pdo);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
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
            'refused: people.title: 1 value is there more than once when case is ignored; the column keeps its collation until they differ',
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

        $this->assertSame('refused: people.title: 22 values are there more than once when case is ignored; the column keeps its collation until they differ', $lines[0]);
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

    /** No version recorded (the first run on 0.78): the indexes are rebuilt anyway, quietly. */
    public function test_a_first_run_reindexes_and_records_the_version_quietly(): void
    {
        $this->sync()->sync(self::people());
        $this->pdo->clear();

        $this->assertSame([], $this->conversion()->convert());
        $this->assertNotSame([], $this->pdo->matching('/^REINDEX\b/i'));
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

        // --pretend predicts the same refusal without rebuilding anything (N2).
        $this->pdo->clear();
        $this->assertSame($lines, $this->conversion()->check());
        $this->assertSame([], $this->pdo->matching('/^\s*(REINDEX|CREATE|DROP|INSERT|DELETE)\b/i'), 'nothing rebuilt or written');
    }

    /** N1: the bookkeeping follows a migration's drop, dropColumn and convertColumn. */
    public function test_the_recorded_intent_follows_drops_and_conversions(): void
    {
        $dir = $this->migration('notes', <<<'PHP'
                    $schema->create('notes', function (Table $t) {
                        $t->uuid('id')->primary();
                        $t->string('title');
                        $t->string('code', 40)->exact();
                        $t->string('gone');
                    });
                    $schema->create('temp', function (Table $t) { $t->uuid('id')->primary(); $t->string('x'); });
                    $schema->dropColumn('notes', 'gone');
                    $schema->drop('temp');
                    $schema->convertColumn('notes', fn (Table $t) => $t->string('code', 40));
                    $schema->convertColumn('notes', fn (Table $t) => $t->string('title')->exact());
            PHP);

        (new Migrator($this->pdo, $dir))->migrate();

        $this->assertSame(
            ['notes' => ['code' => 'text', 'id' => 'exact', 'title' => 'exact']],
            (new MigrationColumns($this->pdo))->tables(),
        );
    }

    /** N6: a table made before 0.78 and extended by a later migration: its older text columns are listed once. */
    public function test_a_table_with_intent_for_some_columns_lists_the_older_ones_once(): void
    {
        $this->pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, title TEXT, n INTEGER)');
        $dir = $this->migration('notes_more', "        \$schema->addColumns('notes', fn (Table \$t) => \$t->string('summary')->nullable());");
        (new Migrator($this->pdo, $dir))->migrate();

        $lines = $this->conversion()->convert();

        $this->assertSame('preflow_text', Grammars::for($this->pdo)->columnCollations($this->pdo, 'notes')['summary'], 'the new column follows the rule already');
        $this->assertContains('warning: notes has columns without a recorded text rule (made before 0.78): id, title keep their comparison. Adopt each with a migration (Schema::convertColumn(), see the data README) or list the table in text_rule.skip', $lines);
        $this->assertSame([], array_values(array_filter($this->conversion()->convert(), static fn (string $l): bool => str_contains($l, 'notes'))), 'listed once');
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

    /** A table the rebuild cannot keep as it is (a CHECK constraint) is converted on purpose, not by running migrate again. */
    public function test_a_table_the_rebuild_cannot_keep_is_left_with_the_way_to_convert_it(): void
    {
        $this->pdo->exec("CREATE TABLE people (uuid TEXT PRIMARY KEY, title TEXT, body TEXT CHECK (body <> 'x'), d TEXT)");
        $this->pdo->exec("INSERT INTO people (uuid, title) VALUES ('r1', 'Müller')");

        $lines = $this->sync()->sync(self::people());

        $this->assertStringStartsWith('refused: people: Cannot convert the collations of "people" on SQLite', $lines[0]);
        $this->assertStringEndsWith('; convert the table (see the data README)', $lines[0]);
        $this->assertSame('BINARY', Grammars::for($this->pdo)->columnCollations($this->pdo, 'people')['title']);
    }

    /** R41: a migration run by Migrator records each column's intent; migrate later converts by it. */
    public function test_migrations_record_each_columns_intent(): void
    {
        $dir = $this->migration('notes', <<<'PHP'
            $schema->create('notes', function (Table $t) {
                $t->uuid('id')->primary();
                $t->string('title');
                $t->string('code', 40)->exact();
                $t->integer('n')->nullable();
            });
            $schema->addColumns('notes', fn (Table $t) => $t->text('summary'));
            $schema->renameColumn('notes', 'summary', 'abstract');
            PHP);

        (new Migrator($this->pdo, $dir))->migrate();

        $this->assertSame(
            ['notes' => ['abstract' => 'text', 'code' => 'exact', 'id' => 'exact', 'n' => 'exact', 'title' => 'text']],
            (new MigrationColumns($this->pdo))->tables(),
        );
        // A table made before (BINARY) with recorded intent: its text columns get the rule.
        $this->pdo->exec('DROP TABLE notes');
        $this->pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, title VARCHAR(255) NOT NULL, code VARCHAR(40) NOT NULL, n INTEGER, abstract TEXT)');
        $this->pdo->exec('CREATE UNIQUE INDEX uniq_notes_title ON notes (title)');
        $this->pdo->exec("INSERT INTO notes (id, title, code) VALUES ('a', 'Straße', 'Ab'), ('b', 'STRAẞE', 'aB')");

        // Migrator's own table is the framework's (Application declares it).
        $declared = new DeclaredTables();
        $declared->add('preflow_migrations', ['migration' => 'exact', 'ran_at' => 'exact']);
        $lines = $this->conversion($declared)->convert();

        $this->assertSame([
            'refused: notes.title: 1 value is there more than once when case is ignored; the column keeps its collation until they differ',
            '  "Straße" in a, b',
            'converted collations of notes: abstract',
        ], $lines);
        $this->assertSame(['id' => 'BINARY', 'title' => 'BINARY', 'code' => 'BINARY', 'n' => null, 'abstract' => 'preflow_text'], Grammars::for($this->pdo)->columnCollations($this->pdo, 'notes'));
        $this->pdo->exec("UPDATE notes SET title = 'Straße 2' WHERE id = 'b'");
        $this->assertSame(['converted collations of notes: title'], $this->conversion($declared)->convert());
        $this->assertSame([], $this->conversion($declared)->convert());
    }

    /** R41/R40: declared framework tables get their intent; R17: only on the storage the migrations run on. */
    public function test_declared_tables_convert_on_the_migrated_storage_only(): void
    {
        $second = new StatementLog('sqlite:' . $this->dir . '/second.sqlite');
        $second->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        SqliteDialect::prepare($second);
        foreach ([$this->pdo, $second] as $pdo) {
            $pdo->exec('CREATE TABLE users (uuid TEXT PRIMARY KEY, email TEXT, passwordHash TEXT)');
            $pdo->exec("INSERT INTO users VALUES ('u1', 'anna@example.org', 'HASH')");
        }
        (new MigrationColumns($this->pdo))->ensure(); // the migrator ran here
        $declared = new DeclaredTables();
        $declared->add('users', ['uuid' => 'exact', 'email' => 'text', 'passwordHash' => 'exact']);
        $dm = new DataManager(drivers: ['sqlite' => new SqliteDriver($this->pdo), 'second' => new SqliteDriver($second)], defaultDriver: 'sqlite');
        $second->clear();

        $lines = $this->conversion($declared, dm: $dm)->convert();

        $this->assertSame(['converted collations of users: email'], $lines);
        $this->assertSame(['uuid' => 'BINARY', 'email' => 'preflow_text', 'passwordHash' => 'BINARY'], Grammars::for($this->pdo)->columnCollations($this->pdo, 'users'));
        $this->assertSame('BINARY', Grammars::for($second)->columnCollations($second, 'users')['email'], 'the second storage keeps its users table');
        $this->assertSame([], $second->matching('/^\s*(ALTER|CREATE|DROP|INSERT)\b.*users/is'));
    }

    /** R41: a table without known intent (a site migration before 0.78) is left alone and listed once. */
    public function test_tables_without_known_intent_are_listed_once_and_left_alone(): void
    {
        $this->pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, title VARCHAR(255) NOT NULL, n INTEGER)');
        $this->pdo->exec('CREATE TABLE counts (id INTEGER PRIMARY KEY, n INTEGER)');
        (new MigrationColumns($this->pdo))->ensure();
        $this->pdo->clear();

        $this->assertSame(
            ['warning: notes has no recorded text rule (made by a migration before 0.78, or not by Preflow): its columns id, title keep their comparison. Adopt it with a migration (Schema::convertColumn(), see the data README) or list it in text_rule.skip'],
            $this->conversion()->convert(),
        );
        $this->assertSame('BINARY', Grammars::for($this->pdo)->columnCollations($this->pdo, 'notes')['title']);
        $this->assertSame([], $this->pdo->matching('/^\s*(ALTER|CREATE|DROP|REINDEX)\b.*notes/is'));
        $this->assertSame([], $this->conversion()->convert(), 'listed once');
        $this->assertSame([], $this->conversion(migrated: null)->convert(), 'nothing listed where the migrations did not run');
    }

    /** I4: a grammar with TextCollations alone makes new columns right and says once that it leaves old ones. */
    public function test_a_grammar_that_cannot_convert_is_reported_once_and_changes_nothing(): void
    {
        $this->oldPeople();
        $this->pdo->exec("INSERT INTO people (uuid, title, body) VALUES ('r1', 'Müller', 'eins')");
        $declared = new DeclaredTables();
        $declared->add('users', ['uuid' => 'exact', 'email' => 'text']);
        $this->pdo->exec('CREATE TABLE users (uuid TEXT PRIMARY KEY, email TEXT)');
        Grammars::register('sqlite', new RuleOnlyGrammar());
        try {
            $this->pdo->clear();

            $this->assertSame([sprintf(TextRuleConversion::NO_CONVERSION, RuleOnlyGrammar::class)], $this->conversion($declared)->convert());
            $this->assertSame([], $this->sync()->sync(self::people()), 'the record table keeps its columns, quietly');
            $this->assertSame([], $this->pdo->matching('/^\s*(ALTER|CREATE|DROP|REINDEX)\b/i'), 'nothing changed');

            $this->pdo->exec('DROP TABLE people');
            $this->sync()->sync(self::people());
            $this->assertStringContainsString('COLLATE preflow_text', (string) $this->pdo->query("SELECT sql FROM sqlite_master WHERE name = 'people'")->fetchColumn(), 'a new table gets the rule');
        } finally {
            Grammars::forget('sqlite');
        }
    }

    /** --pretend: the refusals, nothing changed. */
    public function test_the_check_lists_refusals_and_changes_nothing(): void
    {
        $this->oldPeople();
        $this->pdo->exec("INSERT INTO people (uuid, title) VALUES ('r1', 'Müller'), ('r2', 'MÜLLER')");
        $this->pdo->clear();

        $this->assertSame([
            'refused: people.title: 1 value is there more than once when case is ignored; the column keeps its collation until they differ',
            '  "Müller" in r1, r2',
        ], $this->conversion()->check());
        $this->assertSame([], $this->pdo->matching('/^\s*(ALTER|CREATE|DROP|INSERT|UPDATE|DELETE|REINDEX)\b/i'));
    }

    private function migration(string $name, string $body): string
    {
        $dir = $this->dir . '/migrations';
        @mkdir($dir);
        file_put_contents($dir . "/2026_01_01_000000_{$name}.php", "<?php\nuse Preflow\\Data\\Migration\\Migration;\nuse Preflow\\Data\\Migration\\Schema;\nuse Preflow\\Data\\Migration\\Table;\n"
            . "return new class extends Migration {\n    public function up(Schema \$schema): void\n    {\n{$body}\n    }\n};\n");

        return $dir;
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

    /** @param list<string> $skip */
    private function conversion(?DeclaredTables $declared = null, ?string $migrated = 'sqlite', array $skip = [], ?DataManager $dm = null): TextRuleConversion
    {
        $tables = new RecordTables();
        $tables->add('test', static fn (): array => [RecordTables::entry(self::people())]);

        return new TextRuleConversion($dm ?? $this->dm(), $tables, $declared, $migrated, $skip);
    }
}
