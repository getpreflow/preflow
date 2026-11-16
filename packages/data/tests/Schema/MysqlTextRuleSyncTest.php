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

        $this->assertSame(['converted collations of people: title, body, d'], $lines, 'the date was utf8mb4_unicode_ci, now exact');
        $this->assertSame(
            ['uuid' => 'utf8mb4_bin', 'title' => $this->text, 'body' => $this->text, 'd' => 'utf8mb4_bin'],
            Grammars::for($this->pdo)->columnCollations($this->pdo, 'people'),
        );
        $this->assertSame([['uuid' => 'r1', 'title' => 'Müller', 'body' => 'Text', 'd' => '2026-01-02']], $this->rows());
        $this->assertSame(1, count($this->pdo->matching('/^ALTER TABLE/')), 'one statement');

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
            'refused: people.title: 1 value is there more than once when case is ignored; the column keeps its collation until they differ',
            '  "Müller" in r1, r2',
            'converted collations of people: body, d',
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

        $this->assertSame(['converted collations of code: title, purpose'], $this->sync()->sync(self::code()));
        $this->assertSame(['uuid' => 'utf8mb4_bin', 'title' => $this->text, 'purpose' => $this->text], Grammars::for($this->pdo)->columnCollations($this->pdo, 'code'));
    }

    public function test_a_server_without_the_collation_changes_nothing(): void
    {
        $this->oldPeople();
        Grammars::register('mysql', new MysqlGrammar(collations: []));
        $this->pdo->clear();

        $this->assertSame([], $this->sync()->sync(self::people()));
        $this->assertSame(
            ['warning: this server has no accent-sensitive, case-insensitive collation (MariaDB 10.10+ or MySQL 8.0+); text columns keep their comparison'],
            $this->conversion()->convert(),
        );
        $this->assertSame([], $this->pdo->matching('/^\s*(ALTER|CREATE|DROP)/i'));
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

        $this->assertSame(['converted collations of post: title, body, tags'], $lines);
        $this->assertCount(1, $this->pdo->matching('/^ALTER TABLE/'));
        $this->assertSame(['uuid' => 'utf8mb4_bin', 'title' => $this->text, 'body' => $this->text, 'tags' => $this->text], Grammars::for($this->pdo)->columnCollations($this->pdo, 'post'));
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
            'converted collations of notes: title',
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

        $this->assertSame(['converted collations of people__revisions: title, body, record_id'], $this->sync()->sync($history, ['record_id'], typed: false));
        $this->assertSame(['uuid' => 'utf8mb4_bin', 'title' => $this->text, 'body' => $this->text, 'record_id' => 'utf8mb4_bin'], Grammars::for($this->pdo)->columnCollations($this->pdo, 'people__revisions'));
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
    private function conversion(?DeclaredTables $declared = null, array $skip = []): TextRuleConversion
    {
        return new TextRuleConversion($this->dm(), new RecordTables(), $declared, 'mysql', $skip);
    }
}
