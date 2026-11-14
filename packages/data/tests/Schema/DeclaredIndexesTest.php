<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\SqliteGrammar;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** Declared indexes on SQLite: made when missing on every sync, on existing columns too; composite ones. */
final class DeclaredIndexesTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
    }

    /** A site whose jobs table was made before the index was declared gets it on the next migrate. */
    public function testDeclaredIndexReachesAnExistingTable(): void
    {
        $sync = $this->sync();
        $sync->sync(self::jobs(index: false));
        $this->pdo->exec("INSERT INTO jobs (uuid, company, title) VALUES ('a', 'acme', 'One'), ('b', 'acme', 'Two')");

        $this->assertSame(['added index idx_jobs_company'], $sync->sync(self::jobs()));
        $this->assertContains('idx_jobs_company', $this->indexes('jobs'));
        $this->assertSame([], $sync->sync(self::jobs()), 'a second sync is a no-op');
    }

    public function testCompositeIndexIsMadeWithTheTableAndOnAnExistingOne(): void
    {
        $def = self::jobs(indexes: [['state', 'updated_at']]);
        $this->assertSame(['created table jobs', 'added index idx_jobs_company', 'added index idx_jobs_state_updated_at'], $this->sync()->sync($def));
        $this->assertSame(['state', 'updated_at'], (new SqliteGrammar())->indexColumns($this->pdo, 'jobs')['idx_jobs_state_updated_at']);

        $this->pdo->exec('DROP TABLE jobs');
        $this->sync()->sync(self::jobs());
        $this->assertSame(['added index idx_jobs_state_updated_at'], $this->sync()->sync($def));
        $this->assertSame([], $this->sync()->sync($def));
    }

    /** A composite index's string field is stored as an indexed one: a bounded column with its length rule. */
    public function testCompositeIndexFieldsAreStoredAsIndexed(): void
    {
        $def = self::jobs(indexes: [['state', 'updated_at']]);

        $this->assertSame(ColumnKind::Short, $this->sync()->specs($def, [], true)['state']->kind);
        $this->assertSame(ColumnKind::Text, $this->sync()->specs(self::jobs(), [], true)['state']->kind);
        $this->assertArrayHasKey('state', $this->dm()->fieldKinds()->lengths($def));
    }

    public function testLongCompositeIndexNameIsShortened(): void
    {
        $long = str_repeat('f', 30);
        $def = new TypeDefinition('jobs', 'jobs', 'sqlite', [
            $long => new TypeFieldDefinition($long),
            'state' => new TypeFieldDefinition('state'),
        ], indexes: [[$long, 'state']]);

        $lines = $this->sync()->sync($def);

        $name = (new SqliteGrammar())->indexName('idx', 'jobs', "{$long}_state");
        $this->assertSame(['created table jobs', "added index {$name}"], $lines);
        $this->assertLessThanOrEqual(64, strlen($name));
        $this->assertSame([], $this->sync()->sync($def));
    }

    public function testLateUniqueIndexWhenTheColumnHoldsNoDuplicates(): void
    {
        $sync = $this->sync();
        $sync->sync(self::slugs(unique: false));
        $this->pdo->exec("INSERT INTO page (uuid, slug) VALUES ('a', 'one'), ('b', 'two'), ('c', NULL), ('d', NULL)");

        $this->assertSame(['added unique index uniq_page_slug'], $sync->sync(self::slugs()));
        $this->assertSame([], $sync->sync(self::slugs()));
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO page (uuid, slug) VALUES ('e', 'one')");
    }

    public function testExistingDuplicatesKeepTheUniqueWarning(): void
    {
        $sync = $this->sync();
        $sync->sync(self::slugs(unique: false));
        $this->pdo->exec("INSERT INTO page (uuid, slug) VALUES ('a', 'one'), ('b', 'one'), ('c', 'two'), ('d', 'two'), ('e', 'two'), ('f', 'three')");

        $warning = 'warning: page.slug has no unique index (2 values are there more than once); remove the duplicates (tools/convert-typed-columns.php --check lists them), the next migrate adds it';
        $this->assertSame([$warning], $sync->sync(self::slugs()));
        $this->assertNotContains('uniq_page_slug', $this->indexes('page'));
        $this->assertSame([$warning], $sync->sync(self::slugs()), 'nothing changed, so the same warning again');

        $this->pdo->exec("DELETE FROM page WHERE uuid IN ('b', 'd')");
        $this->assertSame(['warning: page.slug has no unique index (1 value is there more than once); remove the duplicates (tools/convert-typed-columns.php --check lists them), the next migrate adds it'], $sync->sync(self::slugs()));
        $this->pdo->exec("DELETE FROM page WHERE uuid = 'e'");
        $this->assertSame(['added unique index uniq_page_slug'], $sync->sync(self::slugs()));
    }

    public function testScopedDuplicatesCountOverTheScope(): void
    {
        $plain = new TypeDefinition('code', 'code', 'sqlite', ['code' => new TypeFieldDefinition('code', index: true), 'purpose' => new TypeFieldDefinition('purpose', index: true)]);
        $scoped = new TypeDefinition('code', 'code', 'sqlite', ['code' => new TypeFieldDefinition('code', unique: ['purpose']), 'purpose' => new TypeFieldDefinition('purpose')]);
        $sync = $this->sync();
        $sync->sync($plain);
        $this->pdo->exec("INSERT INTO code (uuid, code, purpose) VALUES ('a', 'X1', 'invite'), ('b', 'X1', 'reset'), ('c', 'X1', 'invite')");

        $this->assertSame(['warning: code.code has no unique index (1 value is there more than once); remove the duplicates (tools/convert-typed-columns.php --check lists them), the next migrate adds it'], $sync->sync($scoped));

        $this->pdo->exec("UPDATE code SET purpose = NULL WHERE uuid = 'c'");
        $this->assertSame(['added unique index uniq_code_code'], $sync->sync($scoped), 'a NULL scope never collides');
    }

    public function testAHandMadeIndexUnderAnotherNameIsEnough(): void
    {
        $sync = $this->sync();
        $sync->sync(new TypeDefinition('item', 'item', 'sqlite', ['number' => new TypeFieldDefinition('number', 'number')]));
        $this->pdo->exec('CREATE INDEX idx_published_number ON item (number)');

        $this->assertSame([], $sync->sync(new TypeDefinition('item', 'item', 'sqlite', ['number' => new TypeFieldDefinition('number', 'number', index: true)])));
        $this->assertSame(['idx_published_number'], $this->indexes('item'));
    }

    public function testGrammarsTellUniqueIndexes(): void
    {
        $this->sync()->sync(self::slugs());
        $this->pdo->exec('CREATE INDEX plain_slug ON page (slug)');

        $this->assertSame(['uniq_page_slug'], array_values(array_filter((new SqliteGrammar())->uniqueIndexes($this->pdo, 'page'), static fn (string $i): bool => !str_starts_with($i, 'sqlite_'))));
    }

    /** An index made by hand under another name (a site's own migration) is found by its columns. */
    public function testAnIndexOverTheSameColumnsIsFoundByItsColumns(): void
    {
        $plain = new TypeDefinition('item', 'item', 'sqlite', ['number' => new TypeFieldDefinition('number', 'number'), 'code' => new TypeFieldDefinition('code', index: true)]);
        $declared = new TypeDefinition('item', 'item', 'sqlite', ['number' => new TypeFieldDefinition('number', 'number', index: true), 'code' => new TypeFieldDefinition('code', unique: true)], indexes: [['code', 'number']]);
        $sync = $this->sync();
        $sync->sync($plain);
        $this->pdo->exec('CREATE INDEX idx_published_number ON item (number)');
        $this->pdo->exec('CREATE INDEX by_code_number ON item (code, number)');

        $this->assertSame(['added unique index uniq_item_code'], $sync->sync($declared), 'a plain index over code does not make it unique');
        $this->assertEqualsCanonicalizing(['idx_item_code', 'idx_published_number', 'by_code_number', 'uniq_item_code'], $this->indexes('item'));

        $this->pdo->exec('DROP INDEX uniq_item_code');
        $this->pdo->exec('CREATE UNIQUE INDEX item_code_unique ON item (code)');
        $this->assertSame([], $sync->sync($declared), 'a unique index over the field under another name');
    }

    public function testAnIndexInTheOtherOrderIsNotTheSame(): void
    {
        $def = self::jobs(indexes: [['state', 'updated_at']]);
        $this->sync()->sync(self::jobs());
        $this->pdo->exec('CREATE INDEX by_date_state ON jobs (updated_at, state)');

        $this->assertSame(['added index idx_jobs_state_updated_at'], $this->sync()->sync($def));
    }

    /** A kind only FieldKinds knows as free text (text, blocks, a multiple relation) can't carry the declared index: a warning, every run. */
    public function testAnIndexOverFreeTextIsAWarning(): void
    {
        $def = new TypeDefinition('post', 'post', 'sqlite', [
            'body' => new TypeFieldDefinition('body', 'text', index: true),
            'tags' => new TypeFieldDefinition('tags', 'relation', config: ['relation' => ['to' => 'tag', 'multiple' => true]], index: true),
            'state' => new TypeFieldDefinition('state'),
        ], indexes: [['state', 'body']]);

        $warnings = [
            'warning: post: no index over body (body is free text); see the data README',
            'warning: post: no index over tags (tags is free text); see the data README',
            'warning: post: no index over state, body (body is free text); see the data README',
        ];
        $this->assertSame(['created table post', ...$warnings], $this->sync()->sync($def));
        $this->assertSame([], $this->indexes('post'));
        $this->assertSame($warnings, $this->sync()->sync($def));
    }

    /** A save writing a duplicate between the count and the CREATE: the database refuses, sync warns. */
    public function testARefusedUniqueIndexIsAWarning(): void
    {
        $sync = $this->sync();
        $sync->sync(self::slugs(unique: false));
        $this->pdo->exec("INSERT INTO page (uuid, slug) VALUES ('a', 'one')");
        Grammars::register('sqlite', new RacingGrammar($this->pdo, "INSERT INTO page (uuid, slug) VALUES ('b', 'one')"));
        try {
            $lines = $sync->sync(self::slugs());
        } finally {
            Grammars::forget('sqlite');
        }

        $this->assertSame(['warning: page.slug has no unique index; convert the table (see the data README)'], $lines);
        $this->assertNotContains('uniq_page_slug', $this->indexes('page'));
    }

    /** A plain index the server refuses (here: its name is taken on another table) is a warning; the sync goes on. */
    public function testARefusedPlainIndexIsAWarning(): void
    {
        $this->pdo->exec('CREATE TABLE other (x TEXT)');
        $this->pdo->exec('CREATE INDEX idx_jobs_company ON other (x)');

        $lines = $this->sync()->sync(self::jobs(indexes: [['state', 'updated_at']]));

        $this->assertSame('created table jobs', $lines[0]);
        $this->assertStringStartsWith('warning: jobs: no index over company (the server refused it: ', $lines[1]);
        $this->assertStringEndsWith('; see the data README', $lines[1]);
        $this->assertSame('added index idx_jobs_state_updated_at', $lines[2]);
        $this->assertCount(3, $lines);
    }

    /** SQLite has no key size limit: an index past MySQL's 3072 bytes is made. */
    public function testALongKeyIsMadeOnSqlite(): void
    {
        $def = new TypeDefinition('job', 'jobs', 'sqlite', [
            'a' => new TypeFieldDefinition('a', length: 500),
            'b' => new TypeFieldDefinition('b', length: 300),
            'n' => new TypeFieldDefinition('n', 'number'),
        ], indexes: [['a', 'b', 'n']]);

        $this->assertSame(['created table jobs', 'added index idx_jobs_a_b_n'], $this->sync()->sync($def));
    }

    /** @param list<list<string>> $indexes */
    private static function jobs(bool $index = true, array $indexes = []): TypeDefinition
    {
        return new TypeDefinition('jobs', 'jobs', 'sqlite', [
            'company' => new TypeFieldDefinition('company', index: $index),
            'title' => new TypeFieldDefinition('title'),
            'state' => new TypeFieldDefinition('state'),
            'updated_at' => new TypeFieldDefinition('updated_at', 'date'),
        ], indexes: $indexes);
    }

    private static function slugs(bool $unique = true): TypeDefinition
    {
        return new TypeDefinition('page', 'page', 'sqlite', ['slug' => new TypeFieldDefinition('slug', unique: $unique)]);
    }

    /** @return list<string> without SQLite's own indexes */
    private function indexes(string $table): array
    {
        return array_values(array_filter((new Schema($this->pdo))->indexes($table), static fn (string $i): bool => !str_starts_with($i, 'sqlite_')));
    }

    private function dm(): DataManager
    {
        return new DataManager(drivers: ['sqlite' => new SqliteDriver($this->pdo)], defaultDriver: 'sqlite');
    }

    private function sync(): RecordTableSync
    {
        return new RecordTableSync($this->dm());
    }
}

/** SQLite's grammar, but a "concurrent save" runs just before a unique index is created. */
final class RacingGrammar implements \Preflow\Data\Migration\Grammar\SchemaGrammar
{
    private readonly SqliteGrammar $g;

    public function __construct(private readonly \PDO $pdo, private readonly string $save)
    {
        $this->g = new SqliteGrammar();
    }

    public function createRecordIndex(string $name, string $table, array $columns, bool $unique, array $columnTypes = [], array $lengths = []): string
    {
        if ($unique) {
            $this->pdo->exec($this->save);
        }

        return $this->g->createRecordIndex($name, $table, $columns, $unique, $columnTypes, $lengths);
    }

    public function quote(string $identifier): string { return $this->g->quote($identifier); }
    public function columnType(string $kind, ?int $length = null): string { return $this->g->columnType($kind, $length); }
    public function createTable(string $table, array $columns): string { return $this->g->createTable($table, $columns); }
    public function createRecordTable(string $table, string $idField, array $columns): string { return $this->g->createRecordTable($table, $idField, $columns); }
    public function recordColumnType(ColumnKind $kind, ?int $length = null): string { return $this->g->recordColumnType($kind, $length); }
    public function indexName(string $prefix, string $table, string $field): string { return $this->g->indexName($prefix, $table, $field); }
    public function createIndex(string $table, string $column): string { return $this->g->createIndex($table, $column); }
    public function dropTable(string $table): string { return $this->g->dropTable($table); }
    public function hasTable(\PDO $pdo, string $table): bool { return $this->g->hasTable($pdo, $table); }
    public function columns(\PDO $pdo, string $table): array { return $this->g->columns($pdo, $table); }
    public function columnTypes(\PDO $pdo, string $table): array { return $this->g->columnTypes($pdo, $table); }
    public function indexes(\PDO $pdo, string $table): array { return $this->g->indexes($pdo, $table); }
    public function indexColumns(\PDO $pdo, string $table): array { return $this->g->indexColumns($pdo, $table); }
    public function uniqueIndexes(\PDO $pdo, string $table): array { return $this->g->uniqueIndexes($pdo, $table); }
    public function dropIndex(string $table, string $index): string { return $this->g->dropIndex($table, $index); }
    public function assertCanDropColumn(\PDO $pdo): void { $this->g->assertCanDropColumn($pdo); }
    public function addColumn(string $table, string $column, ?string $type = null): string { return $this->g->addColumn($table, $column, $type); }
    public function renameColumn(string $table, string $from, string $to): string { return $this->g->renameColumn($table, $from, $to); }
    public function copyColumn(string $table, string $from, string $to): string { return $this->g->copyColumn($table, $from, $to); }
    public function dropColumn(string $table, string $column): string { return $this->g->dropColumn($table, $column); }
    public function convertColumn(\PDO $pdo, string $table, string $column, string $type, ?bool $nullable): void { $this->g->convertColumn($pdo, $table, $column, $type, $nullable); }
    public function createMigrationsTable(): string { return $this->g->createMigrationsTable(); }
    public function canIndex(string $kind): bool { return $this->g->canIndex($kind); }
}
