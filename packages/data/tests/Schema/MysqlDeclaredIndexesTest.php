<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\MysqlTestDatabase;

/** Declared indexes on MySQL/MariaDB: made when missing on every sync, a TEXT column by a prefix; composite ones. */
#[Group('mysql')]
final class MysqlDeclaredIndexesTest extends TestCase
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

    /** A jobs table made before the index was declared: company is LONGTEXT, indexed by its first 191 characters. */
    public function testDeclaredIndexReachesAnExistingTextColumn(): void
    {
        $sync = $this->sync();
        $sync->sync(self::jobs(index: false));
        $this->pdo->exec("INSERT INTO jobs (uuid, company, title) VALUES ('a', 'acme', 'One'), ('b', 'acme', 'Two')");

        $lines = $sync->sync(self::jobs());

        $drift = 'warning: jobs.company is longtext, expected short; convert the table (see the data README)';
        $this->assertSame([$drift, 'added index idx_jobs_company'], $lines);
        $this->assertSame(['company' => 191], $this->subParts('jobs', 'idx_jobs_company'));
        $this->assertSame([$drift], $sync->sync(self::jobs()), 'a second sync adds nothing');
        $stmt = $this->pdo->query("SELECT uuid FROM jobs WHERE company = 'acme' ORDER BY uuid");
        $this->assertSame(['a', 'b'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testCompositeIndexIsMadeWithTheTableAndOnAnExistingOne(): void
    {
        $def = self::jobs(indexes: [['state', 'updated_at']]);

        $this->assertSame(['created table jobs', 'added index idx_jobs_company', 'added index idx_jobs_state_updated_at'], $this->sync()->sync($def));
        $this->assertSame(['state', 'updated_at'], (new MysqlGrammar())->indexColumns($this->pdo, 'jobs')['idx_jobs_state_updated_at']);
        $this->assertSame(['state' => null, 'updated_at' => null], $this->subParts('jobs', 'idx_jobs_state_updated_at'), 'state is a bounded column');
        $this->assertSame([], $this->sync()->sync($def));

        MysqlTestDatabase::dropAllTables($this->pdo);
        $this->sync()->sync(self::jobs());
        $lines = $this->sync()->sync($def);
        $this->assertSame([
            'warning: jobs.state is longtext, expected short; convert the table (see the data README)',
            'added index idx_jobs_state_updated_at',
        ], $lines);
        $this->assertSame(['state' => 191, 'updated_at' => null], $this->subParts('jobs', 'idx_jobs_state_updated_at'));
    }

    public function testExistingDuplicatesKeepTheUniqueWarning(): void
    {
        $plain = new TypeDefinition('page', 'page', 'mysql', ['slug' => new TypeFieldDefinition('slug', index: true)]);
        $unique = new TypeDefinition('page', 'page', 'mysql', ['slug' => new TypeFieldDefinition('slug', unique: true)]);
        $sync = $this->sync();
        $sync->sync($plain);
        $this->pdo->exec("INSERT INTO page (uuid, slug) VALUES ('a', 'one'), ('b', 'one'), ('c', 'One'), ('d', NULL), ('e', NULL)");

        $this->assertSame(['warning: page.slug has no unique index (1 value is there more than once); remove the duplicates (tools/convert-typed-columns.php --check lists them), the next migrate adds it'], $sync->sync($unique));

        $this->pdo->exec("DELETE FROM page WHERE uuid = 'b'");
        $this->assertSame(['added unique index uniq_page_slug'], $sync->sync($unique), "'one' and 'One' differ (binary), NULLs never collide");
        $this->assertSame([], $sync->sync($unique));
    }

    /** An index over the same columns under another name is found by its columns; a unique one only by a unique one. */
    public function testAnIndexOverTheSameColumnsIsFoundByItsColumns(): void
    {
        $plain = new TypeDefinition('item', 'item', 'mysql', ['number' => new TypeFieldDefinition('number', 'number'), 'code' => new TypeFieldDefinition('code', index: true)]);
        $declared = new TypeDefinition('item', 'item', 'mysql', ['number' => new TypeFieldDefinition('number', 'number', index: true), 'code' => new TypeFieldDefinition('code', unique: true)]);
        $sync = $this->sync();
        $sync->sync($plain);
        $this->pdo->exec('CREATE INDEX idx_published_number ON item (number)');

        $this->assertSame(['added unique index uniq_item_code'], $sync->sync($declared));
        $this->assertEqualsCanonicalizing(['PRIMARY', 'uniq_item_code'], (new MysqlGrammar())->uniqueIndexes($this->pdo, 'item'));

        $this->pdo->exec('DROP INDEX uniq_item_code ON item');
        $this->pdo->exec('CREATE UNIQUE INDEX item_code_unique ON item (code)');
        $this->assertSame([], $sync->sync($declared));
    }

    /** MySQL caps a key at 3072 bytes: an index whose key may be longer, plain or unique, is a warning on every run. */
    public function testAnIndexPastTheKeyLimitIsAWarning(): void
    {
        $def = new TypeDefinition('job', 'jobs', 'mysql', [
            'a' => new TypeFieldDefinition('a', length: 500),
            'b' => new TypeFieldDefinition('b', length: 300),
            'n' => new TypeFieldDefinition('n', 'number'),
            'code' => new TypeFieldDefinition('code', unique: ['a'], length: 400),
        ], indexes: [['a', 'b', 'n'], ['a', 'n']]);

        $warnings = [
            'warning: jobs: no index over code, a (a key of up to 3600 bytes; MySQL allows 3072); give its text fields a shorter "length"',
            'warning: jobs: no index over a, b, n (a key of up to 3208 bytes; MySQL allows 3072); give its text fields a shorter "length"',
        ];
        $this->assertSame(['created table jobs', ...$warnings, 'added index idx_jobs_a_n'], $this->sync()->sync($def));
        $this->assertSame($warnings, $this->sync()->sync($def));
    }

    /** A TEXT column's prefix is the field's length where that is shorter than 191. */
    public function testThePrefixIsTheDeclaredLength(): void
    {
        $plain = new TypeDefinition('jobs', 'jobs', 'mysql', ['code' => new TypeFieldDefinition('code')]);
        $indexed = new TypeDefinition('jobs', 'jobs', 'mysql', ['code' => new TypeFieldDefinition('code', index: true, length: 40)]);
        $this->sync()->sync($plain);

        $this->assertContains('added index idx_jobs_code', $this->sync()->sync($indexed));
        $this->assertSame(['code' => 40], $this->subParts('jobs', 'idx_jobs_code'));
    }

    /** @return array<string, ?int> each column of the index with its prefix length (null: the whole value) */
    private function subParts(string $table, string $index): array
    {
        $stmt = $this->pdo->prepare('SELECT COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
        $stmt->execute([$table, $index]);
        $parts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$column, $sub]) {
            $parts[(string) $column] = $sub === null ? null : (int) $sub;
        }

        return $parts;
    }

    /** @param list<list<string>> $indexes */
    private static function jobs(bool $index = true, array $indexes = []): TypeDefinition
    {
        return new TypeDefinition('jobs', 'jobs', 'mysql', [
            'company' => new TypeFieldDefinition('company', index: $index),
            'title' => new TypeFieldDefinition('title'),
            'state' => new TypeFieldDefinition('state'),
            'updated_at' => new TypeFieldDefinition('updated_at', 'date'),
        ], indexes: $indexes);
    }

    private function sync(): RecordTableSync
    {
        return new RecordTableSync(new DataManager(drivers: ['mysql' => new MysqlDriver($this->pdo)], defaultDriver: 'mysql'));
    }
}
