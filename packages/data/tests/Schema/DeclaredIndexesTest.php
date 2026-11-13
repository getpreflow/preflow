<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
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

        $warning = 'warning: page.slug has no unique index (2 values are there more than once); convert the table (see the data README)';
        $this->assertSame([$warning], $sync->sync(self::slugs()));
        $this->assertNotContains('uniq_page_slug', $this->indexes('page'));
        $this->assertSame([$warning], $sync->sync(self::slugs()), 'nothing changed, so the same warning again');

        $this->pdo->exec("DELETE FROM page WHERE uuid IN ('b', 'd')");
        $this->assertSame(['warning: page.slug has no unique index (1 value is there more than once); convert the table (see the data README)'], $sync->sync(self::slugs()));
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

        $this->assertSame(['warning: code.code has no unique index (1 value is there more than once); convert the table (see the data README)'], $sync->sync($scoped));

        $this->pdo->exec("UPDATE code SET purpose = NULL WHERE uuid = 'c'");
        $this->assertSame(['added unique index uniq_code_code'], $sync->sync($scoped), 'a NULL scope never collides');
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
