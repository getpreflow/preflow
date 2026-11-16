<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\MysqlTestDatabase;

/** The FULLTEXT index a type that declares full-text search gets from the table sync. */
#[Group('mysql')]
final class MysqlFullTextTest extends TestCase
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

    public function test_sync_creates_the_full_text_index_for_a_flagged_type(): void
    {
        $lines = $this->sync()->sync(self::post());

        $this->assertSame(['created table post', 'added full-text index ft_post_search'], $lines);
        $this->assertSame(['ft_post_search' => ['title', 'body']], (new MysqlGrammar())->fullTextIndexes($this->pdo, 'post'));
        $this->assertSame([], $this->sync()->sync(self::post()), 'a second sync adds nothing');
    }

    public function test_sync_makes_no_full_text_index_without_the_flag(): void
    {
        $this->sync()->sync(self::post(fullText: false));

        $this->assertSame([], (new MysqlGrammar())->fullTextIndexes($this->pdo, 'post'));
    }

    public function test_sync_replaces_the_index_when_the_text_fields_change(): void
    {
        $this->sync()->sync(self::post());

        $lines = $this->sync()->sync(self::post(extra: true));

        $this->assertSame(['added column post.summary', 'dropped full-text index ft_post_search', 'added full-text index ft_post_search'], $lines);
        $this->assertSame(['ft_post_search' => ['title', 'body', 'summary']], (new MysqlGrammar())->fullTextIndexes($this->pdo, 'post'));
    }

    public function test_convert_column_keeps_the_full_text_index(): void
    {
        $this->sync()->sync(self::post());

        (new Schema($this->pdo))->convertColumn('post', new ColumnSpec('body', ColumnKind::Text, text: true)); // a FULLTEXT index needs one collation over its columns

        $this->assertSame(['ft_post_search' => ['title', 'body']], (new MysqlGrammar())->fullTextIndexes($this->pdo, 'post'));
        $this->assertSame([], $this->sync()->sync(self::post()));
    }

    private static function post(bool $fullText = true, bool $extra = false): TypeDefinition
    {
        $fields = [
            'title' => new TypeFieldDefinition('title'),
            'body' => new TypeFieldDefinition('body', 'text'),
            'views' => new TypeFieldDefinition('views', 'number'),
        ];
        if ($extra) {
            $fields['summary'] = new TypeFieldDefinition('summary', 'text');
        }

        return new TypeDefinition('post', 'post', 'mysql', $fields, fullText: $fullText);
    }

    private function sync(): RecordTableSync
    {
        return new RecordTableSync(new DataManager(drivers: ['mysql' => new MysqlDriver($this->pdo)], defaultDriver: 'mysql'));
    }
}
