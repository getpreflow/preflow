<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

require_once __DIR__ . '/StatementLog.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Migration\MigrationColumns;
use Preflow\Data\Schema\DeclaredTables;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\Schema\TextRuleConversion;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\MysqlTestDatabase;

/**
 * `preflow migrate` gives the tables MySQL/MariaDB sites made before 0.78 the text rule (data
 * README, "Exact comparisons"): one ALTER TABLE per table, a unique column whose values would
 * collide left as it is with its duplicates listed, declared and recorded tables given their
 * columns' intent, tables without known intent listed and left alone.
 */
#[Group('mysql')]
final class MysqlTextRuleSyncTest extends TestCase
{
    private StatementLog $pdo;
    private string $text;

    protected function setUp(): void
    {
        $c = MysqlTestDatabase::dataConfig();
        $this->pdo = new StatementLog(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            MysqlDriver::connectionOptions(),
        );
        MysqlTestDatabase::dropAllTables($this->pdo);
        $text = (new MysqlGrammar())->on($this->pdo)->textCollation();
        if ($text === null) {
            $this->markTestSkipped('The server has none of MysqlDialect::MATCH_COLLATIONS.');
        }
        $this->text = $text;
    }

    protected function tearDown(): void
    {
        Grammars::forget('mysql');
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }

    public function test_an_old_table_is_converted_once(): void
    {
        $this->oldPeople();
        $this->pdo->exec("INSERT INTO people (uuid, title, body, d) VALUES ('r1', 'Müller', 'Text', '2026-01-02')");

        $lines = $this->sync()->sync(self::people());

        $this->assertSame(['added column people._updated_at', 'converted collations of people: title, body, d', $this->defaultLine('people')], $lines, 'the date was utf8mb4_unicode_ci, now exact');
        $this->assertSame(
            ['uuid' => 'utf8mb4_bin', 'title' => $this->text, 'body' => $this->text, 'd' => 'utf8mb4_bin', '_updated_at' => 'utf8mb4_bin'],
            Grammars::for($this->pdo)->columnCollations($this->pdo, 'people'),
        );
        $this->assertSame([['uuid' => 'r1', 'title' => 'Müller', 'body' => 'Text', 'd' => '2026-01-02']], $this->rows());
        $this->assertSame(1, count($this->pdo->matching('/^ALTER TABLE `people` MODIFY/')), 'one statement for the columns');
        $this->assertSame(1, count($this->pdo->matching('/^ALTER TABLE `people` DEFAULT CHARACTER SET utf8mb4 COLLATE /')), 'one for the default');

        $this->pdo->clear();
        $this->assertSame([], $this->sync()->sync(self::people()), 'converted once');
        $this->assertSame([], $this->pdo->matching('/^\s*(ALTER|CREATE|DROP)/i'));
    }

    public function test_duplicates_under_the_rule_leave_the_column_and_are_listed(): void
    {
        $this->oldPeople();
        $this->pdo->exec("INSERT INTO people (uuid, title, body) VALUES ('r1', 'Müller', 'eins'), ('r2', 'MÜLLER', 'zwei'), ('r3', 'Muller', 'drei')");
        $before = $this->rows();

        $lines = $this->sync()->sync(self::people());

        $this->assertSame([
            'added column people._updated_at', // a table from before 0.84 gets the change date
            'refused: people.title: 1 value is there more than once when case is ignored; the column keeps its collation until they differ',
            '  "Müller" in r1, r2',
            'converted collations of people: body, d',
            $this->defaultLine('people'),
        ], $lines);
        $collations = Grammars::for($this->pdo)->columnCollations($this->pdo, 'people');
        $this->assertSame('utf8mb4_bin', $collations['title']);
        $this->assertSame($this->text, $collations['body']);
        $this->assertSame($before, $this->rows(), 'no row changed or removed, byte for byte');

        $this->pdo->exec("UPDATE people SET title = 'Müller 2' WHERE uuid = 'r2'");
        $this->assertSame(['converted collations of people: title'], $this->sync()->sync(self::people()));
        $this->assertSame($this->text, Grammars::for($this->pdo)->columnCollations($this->pdo, 'people')['title']);
    }

    public function test_a_scoped_unique_counts_duplicates_within_its_scope(): void
    {
        $this->pdo->exec('CREATE TABLE code (uuid VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, title VARCHAR(191) COLLATE utf8mb4_bin, purpose VARCHAR(191) COLLATE utf8mb4_bin, '
            . 'UNIQUE KEY uniq_code_title (title, purpose), KEY idx_code_purpose (purpose)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec("INSERT INTO code (uuid, title, purpose) VALUES ('r1', 'Müller', 'a'), ('r2', 'MÜLLER', 'b')");

        $this->assertSame(['added column code._updated_at', 'converted collations of code: title, purpose', $this->defaultLine('code')], $this->sync()->sync(self::code()));
        $this->assertSame(['uuid' => 'utf8mb4_bin', 'title' => $this->text, 'purpose' => $this->text, '_updated_at' => 'utf8mb4_bin'], Grammars::for($this->pdo)->columnCollations($this->pdo, 'code'));
    }

    public function test_a_server_without_the_collation_changes_nothing(): void
    {
        $this->oldPeople();
        Grammars::register('mysql', new MysqlGrammar(collations: []));
        $this->pdo->clear();

        $this->assertSame(['added column people._updated_at'], $this->sync()->sync(self::people()), 'only the change date (0.84)');
        $this->assertSame(
            ['warning: this server has no accent-sensitive, case-insensitive collation (MariaDB 10.10+ or MySQL 8.0+); text columns keep their comparison'],
            $this->conversion()->convert(),
        );
        $this->assertSame(['ALTER TABLE `people` ADD COLUMN `_updated_at` VARCHAR(32) COLLATE utf8mb4_bin'], $this->pdo->matching('/^\s*(ALTER|CREATE|DROP)/i'), 'the new column only');
        $this->assertSame('utf8mb4_unicode_ci', Grammars::for($this->pdo)->columnCollations($this->pdo, 'people')['body']);
    }

    /** A FULLTEXT index refuses columns of mixed collations (error 1283): its columns change together. */
    public function test_a_fulltext_table_converts_in_one_statement(): void
    {
        $this->pdo->exec('CREATE TABLE post (uuid VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, title LONGTEXT, body LONGTEXT, tags LONGTEXT, '
            . 'FULLTEXT KEY ft_post_search (title, body, tags)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec("INSERT INTO post (uuid, title, body, tags) VALUES ('p1', 'Kaffeehaus', 'Wiener Melange', '[\"kaffee\"]')");
        $this->pdo->clear();

        $lines = $this->sync()->sync(self::post());

        $this->assertSame(['added column post._updated_at', 'converted collations of post: title, body, tags', $this->defaultLine('post')], $lines);
        $this->assertCount(1, $this->pdo->matching('/^ALTER TABLE `post` MODIFY/'));
        $this->assertSame(['uuid' => 'utf8mb4_bin', 'title' => $this->text, 'body' => $this->text, 'tags' => $this->text, '_updated_at' => 'utf8mb4_bin'], Grammars::for($this->pdo)->columnCollations($this->pdo, 'post'));
        $this->assertSame(['ft_post_search' => ['title', 'body', 'tags']], (new MysqlGrammar())->fullTextIndexes($this->pdo, 'post'));
        $found = $this->pdo->query("SELECT uuid FROM post WHERE MATCH (title, body, tags) AGAINST ('melange' IN BOOLEAN MODE)")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['p1'], $found);
    }

    /** R40/R41: a framework table made before 0.78 (unicode_ci strings) gets its declared intent: machine values exact. */
    public function test_declared_tables_get_their_intent(): void
    {
        $this->pdo->exec('CREATE TABLE auth_tokens (id VARCHAR(255) COLLATE utf8mb4_bin PRIMARY KEY, purpose VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL DEFAULT \'none\', expires_at BIGINT NOT NULL) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec("INSERT INTO auth_tokens VALUES ('t1', 'Reset', 'U1', 1), ('t2', 'reset', 'u1', 2)");
        $this->pdo->exec('CREATE TABLE notes (id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY, title VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec('CREATE TABLE other_app (id INT PRIMARY KEY, name VARCHAR(40)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        (new MigrationColumns($this->pdo))->ensure();
        (new MigrationColumns($this->pdo))->record('notes', 'title', 'text');
        $this->pdo->clear();

        $lines = $this->conversion($this->declared())->convert();

        $this->assertSame([
            'converted collations of auth_tokens: purpose, subject',
            $this->defaultLine('auth_tokens'),
            'converted collations of notes: title',
            $this->defaultLine('notes'),
            'warning: other_app has no recorded text rule (made by a migration before 0.78, or not by Preflow): its columns name keep their comparison. Adopt it with a migration (Schema::convertColumn(), see the data README) or list it in text_rule.skip',
        ], $lines);
        $collations = Grammars::for($this->pdo)->columnCollations($this->pdo, 'auth_tokens');
        $this->assertSame(['id' => 'utf8mb4_bin', 'purpose' => 'utf8mb4_bin', 'subject' => 'utf8mb4_bin', 'expires_at' => null], $collations);
        $this->assertSame($this->text, Grammars::for($this->pdo)->columnCollations($this->pdo, 'notes')['title']);
        $this->assertSame('utf8mb4_unicode_ci', Grammars::for($this->pdo)->columnCollations($this->pdo, 'other_app')['name'], 'a table without known intent is left alone');
        $column = $this->pdo->query("SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_tokens' AND COLUMN_NAME = 'subject'")->fetch(\PDO::FETCH_NUM);
        $this->assertSame('NO', $column[0], 'NOT NULL kept');
        $this->assertStringContainsString('none', (string) $column[1], 'the default kept');
        $this->assertSame([['t1', 'Reset', 'U1'], ['t2', 'reset', 'u1']], $this->pdo->query('SELECT id, purpose, subject FROM auth_tokens ORDER BY id')->fetchAll(\PDO::FETCH_NUM));
        $this->assertSame([], $this->conversion($this->declared())->convert(), 'converted once, listed once');
    }

    /** An older case-insensitive collation (unicode_ci) is not the rule: "STRAẞE" and "Straße" differ there and collide under it. */
    public function test_a_loose_unique_column_that_would_collide_is_listed(): void
    {
        $this->pdo->exec('CREATE TABLE auth_tokens (id VARCHAR(255) COLLATE utf8mb4_bin PRIMARY KEY, purpose VARCHAR(191), subject VARCHAR(191), UNIQUE KEY uniq_tokens (purpose)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec("INSERT INTO auth_tokens VALUES ('t1', 'Straße', 'a'), ('t2', 'STRAẞE', 'b')");
        $declared = new DeclaredTables();
        $declared->add('auth_tokens', ['purpose' => 'text', 'subject' => 'exact']);

        $this->assertSame([
            'refused: auth_tokens.purpose: 1 value is there more than once when case is ignored; the column keeps its collation until they differ',
            '  "Straße" in t1, t2',
            'converted collations of auth_tokens: subject',
            $this->defaultLine('auth_tokens'),
        ], $this->conversion($declared)->convert());
        $this->assertSame('utf8mb4_unicode_ci', Grammars::for($this->pdo)->columnCollations($this->pdo, 'auth_tokens')['purpose']);
    }

    /** A column in another character set is never converted (it would convert its values). */
    public function test_a_column_in_another_character_set_is_left_with_a_warning(): void
    {
        $this->pdo->exec('CREATE TABLE people (uuid VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, title VARCHAR(191) CHARACTER SET latin1 COLLATE latin1_swedish_ci, body LONGTEXT, d VARCHAR(32)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $lines = $this->sync()->sync(self::people());

        $this->assertContains('warning: people.title is in another character set (latin1_swedish_ci); it keeps its collation (convert the table, see the data README)', $lines);
        $this->assertContains('converted collations of people: body, d', $lines);
        $this->assertSame('latin1_swedish_ci', Grammars::for($this->pdo)->columnCollations($this->pdo, 'people')['title']);
    }

    public function test_skipped_tables_are_left_alone(): void
    {
        $this->pdo->exec('CREATE TABLE notes (id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY, title VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        (new MigrationColumns($this->pdo))->record('notes', 'title', 'text');

        $this->assertSame([], $this->conversion(skip: ['notes'])->convert());
        $this->assertSame('utf8mb4_unicode_ci', Grammars::for($this->pdo)->columnCollations($this->pdo, 'notes')['title']);
    }

    public function test_untyped_record_tables_get_the_rule_from_their_sync(): void
    {
        $this->pdo->exec('CREATE TABLE people__revisions (uuid VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, title LONGTEXT, body LONGTEXT, record_id LONGTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $history = new TypeDefinition('people', 'people__revisions', 'mysql', ['title' => new TypeFieldDefinition('title'), 'body' => new TypeFieldDefinition('body', 'text')]);

        $this->assertSame(['added column people__revisions._updated_at', 'converted collations of people__revisions: title, body, record_id', $this->defaultLine('people__revisions')], $this->sync()->sync($history, ['record_id'], typed: false));
        $this->assertSame(['uuid' => 'utf8mb4_bin', 'title' => $this->text, 'body' => $this->text, 'record_id' => 'utf8mb4_bin', '_updated_at' => 'utf8mb4_bin'], Grammars::for($this->pdo)->columnCollations($this->pdo, 'people__revisions'));
    }

    /**
     * A record table made before 0.78 that holds columns its entry no longer lists (the asset
     * table kept the system columns an earlier Folio gave it): every text column gets the rule or
     * the exact collation, and the table default the rule's, so a column added later compares by
     * the rule too. A second run changes nothing.
     */
    public function test_a_record_table_from_before_converts_every_column_and_its_default(): void
    {
        $system = ['_locales', '_order', '_version', '_publish_at', '_share_token', '_published_at', '_published_by'];
        $this->pdo->exec('CREATE TABLE folio_assets (uuid VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, title LONGTEXT, alt LONGTEXT, file LONGTEXT COLLATE utf8mb4_bin, '
            . implode(', ', array_map(static fn (string $c): string => "`{$c}` LONGTEXT", $system))
            . ", removed_field LONGTEXT COLLATE {$this->text}"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec("INSERT INTO folio_assets (uuid, title, alt, file, _share_token) VALUES ('a1', 'Café', 'Tisch', 'a.jpg', 'Tok')");
        $assets = new TypeDefinition('asset', 'folio_assets', 'mysql', [
            'title' => new TypeFieldDefinition('title', 'text'),
            'alt' => new TypeFieldDefinition('alt', 'text'),
            'file' => new TypeFieldDefinition('file', 'string', exact: true),
        ]);

        $lines = $this->sync()->sync($assets);

        $this->assertSame([
            'added column folio_assets._updated_at',
            'converted collations of folio_assets: title, alt, ' . implode(', ', $system),
            $this->defaultLine('folio_assets'),
        ], $lines);
        $collations = Grammars::for($this->pdo)->columnCollations($this->pdo, 'folio_assets');
        $this->assertSame($this->text, $collations['title']);
        $this->assertSame($this->text, $collations['alt']);
        $this->assertSame($this->text, $collations['removed_field'], 'a column without stated intent keeps the rule it has');
        foreach (['uuid', 'file', ...$system] as $column) {
            $this->assertSame('utf8mb4_bin', $collations[$column], "{$column}: a column that is no text field compares exactly");
        }
        $this->assertSame($this->text, $this->tableCollation('folio_assets'));
        $this->assertSame(['Café', 'Tok'], $this->pdo->query('SELECT title, _share_token FROM folio_assets')->fetch(\PDO::FETCH_NUM));

        $this->pdo->clear();
        $this->assertSame([], $this->sync()->sync($assets), 'converted once');
        $this->assertSame([], $this->pdo->matching('/^\s*(ALTER|CREATE|DROP)/i'));
        $this->assertSame([], $this->conversion(records: $this->records($assets))->unconverted(), 'nothing left to list');
    }

    /** A column a migration added to a record table keeps the intent the migration recorded. */
    public function test_a_column_a_migration_added_to_a_record_table_gets_its_recorded_intent(): void
    {
        $this->oldPeople();
        $this->pdo->exec('ALTER TABLE people ADD COLUMN nickname VARCHAR(80), ADD COLUMN code VARCHAR(80)');
        $bookkeeping = new MigrationColumns($this->pdo);
        $bookkeeping->record('people', 'nickname', MigrationColumns::TEXT);
        $bookkeeping->record('people', 'code', MigrationColumns::EXACT);

        $this->sync()->sync(self::people());

        $collations = Grammars::for($this->pdo)->columnCollations($this->pdo, 'people');
        $this->assertSame($this->text, $collations['nickname']);
        $this->assertSame('utf8mb4_bin', $collations['code']);
    }

    /** A declared table's default follows the rule as its columns do. */
    public function test_a_declared_table_gets_the_rule_as_its_default(): void
    {
        $this->pdo->exec('CREATE TABLE notes (id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY, title VARCHAR(255) COLLATE utf8mb4_bin) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $declared = new DeclaredTables();
        $declared->add('notes', ['title' => 'exact']);

        $this->assertSame([$this->defaultLine('notes')], $this->conversion($declared)->convert(), 'its columns were right already');
        $this->assertSame($this->text, $this->tableCollation('notes'));
        $this->assertSame([], $this->conversion($declared)->convert());
    }

    /**
     * After the conversion, a text column of a table Preflow manages that compares neither by the
     * rule nor exactly is listed with its fix (a warning: migrate still succeeds); so is a table
     * default. A table Preflow does not know is not listed (the adopt step lists it once).
     */
    public function test_unconverted_lists_what_still_compares_otherwise(): void
    {
        $this->oldPeople();
        $this->pdo->exec('ALTER TABLE people ADD COLUMN legacy VARCHAR(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci');
        $this->pdo->exec('CREATE TABLE notes (id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY, title VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec('CREATE TABLE other_app (id INT PRIMARY KEY, name VARCHAR(40)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        (new MigrationColumns($this->pdo))->record('notes', 'title', MigrationColumns::TEXT);
        $conversion = $this->conversion(records: $this->records(self::people()));

        $column = static fn (string $column, string $collation): string => "warning: {$column} compares as {$collation}, neither by the text rule nor exactly; "
            . 'convert it with a migration (Schema::convertColumn(), see the data README)';
        $default = static fn (string $table): string => "warning: {$table} has the default collation utf8mb4_unicode_ci (a column added without naming one takes it); run migrate again to convert it";

        $this->assertSame([
            $column('notes.title', 'utf8mb4_unicode_ci'),
            $default('notes'),
            $column('people.body', 'utf8mb4_unicode_ci'),
            $column('people.d', 'utf8mb4_unicode_ci'),
            $column('people.legacy', 'latin1_swedish_ci'),
            $default('people'),
        ], $conversion->unconverted());

        $this->sync()->sync(self::people());
        $conversion->convert();

        $this->assertSame([$column('people.legacy', 'latin1_swedish_ci')], $conversion->unconverted(), 'what migrate cannot convert stays listed');
    }

    /** A column added through Schema::addColumn() without a type compares exactly, whatever the table default. */
    public function test_a_column_added_without_a_type_names_its_collation(): void
    {
        $this->oldPeople();
        (new \Preflow\Data\Migration\Schema($this->pdo))->addColumn('people', 'note');

        $this->assertSame('utf8mb4_bin', Grammars::for($this->pdo)->columnCollations($this->pdo, 'people')['note']);
    }

    private function defaultLine(string $table): string
    {
        return "converted the default collation of {$table} to {$this->text}";
    }

    private function tableCollation(string $table): string
    {
        $stmt = $this->pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);

        return (string) $stmt->fetchColumn();
    }

    private function records(TypeDefinition $def): RecordTables
    {
        $records = new RecordTables();
        $records->add('test', static fn (): array => [RecordTables::entry($def)]);

        return $records;
    }

    private function declared(): DeclaredTables
    {
        $declared = new DeclaredTables();
        $declared->add('preflow_migrations', ['migration' => 'exact', 'ran_at' => 'exact']);
        $declared->add('auth_tokens', ['id' => 'exact', 'purpose' => 'exact', 'subject' => 'exact']);

        return $declared;
    }

    /** The table as a site made it before 0.78 (utf8mb4_unicode_ci default; short columns and ids utf8mb4_bin). */
    private function oldPeople(): void
    {
        $this->pdo->exec('CREATE TABLE people (uuid VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, title VARCHAR(191) COLLATE utf8mb4_bin, body LONGTEXT, d VARCHAR(32), '
            . 'UNIQUE KEY uniq_people_title (title)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->pdo->query('SELECT uuid, title, body, d FROM people ORDER BY uuid')->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function people(): TypeDefinition
    {
        return new TypeDefinition('people', 'people', 'mysql', [
            'title' => new TypeFieldDefinition('title', unique: true),
            'body' => new TypeFieldDefinition('body', 'text'),
            'd' => new TypeFieldDefinition('d', 'date'),
        ]);
    }

    private static function code(): TypeDefinition
    {
        return new TypeDefinition('code', 'code', 'mysql', [
            'title' => new TypeFieldDefinition('title', unique: ['purpose']),
            'purpose' => new TypeFieldDefinition('purpose'),
        ]);
    }

    private static function post(): TypeDefinition
    {
        return new TypeDefinition('post', 'post', 'mysql', [
            'title' => new TypeFieldDefinition('title'),
            'body' => new TypeFieldDefinition('body', 'text'),
            'tags' => new TypeFieldDefinition('tags', 'tags'),
        ], fullText: true);
    }

    private function dm(): DataManager
    {
        $dm = new DataManager(drivers: ['mysql' => new MysqlDriver($this->pdo)], defaultDriver: 'mysql');
        // Folio's tags: searchable text, a list (compared exactly).
        $dm->fieldKinds()->text('tags');
        $dm->fieldKinds()->list('tags');

        return $dm;
    }

    private function sync(): RecordTableSync
    {
        return new RecordTableSync($this->dm());
    }

    /** @param list<string> $skip */
    private function conversion(?DeclaredTables $declared = null, array $skip = [], ?RecordTables $records = null): TextRuleConversion
    {
        return new TextRuleConversion($this->dm(), $records ?? new RecordTables(), $declared, 'mysql', $skip);
    }
}
