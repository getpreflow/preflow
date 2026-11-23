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

        $this->assertSame(['created table post', 'added index idx_post__updated_at', 'added full-text index ft_post_search'], $lines);
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

    /**
     * A FULLTEXT index takes columns of one collation: a full-text type's tags get the rule's
     * collation with the other text fields and keep comparing exactly in queries; a field marked
     * exact is no searchable text, stays out of the index and keeps utf8mb4_bin.
     */
    public function test_tags_join_the_index_and_stay_exact_an_exact_field_stays_out(): void
    {
        $dm = new DataManager(drivers: ['mysql' => new MysqlDriver($this->pdo)], defaultDriver: 'mysql');
        $dm->fieldKinds()->text('tags');
        $dm->fieldKinds()->list('tags');
        $def = new TypeDefinition('note', 'note', 'mysql', [
            'title' => new TypeFieldDefinition('title'),
            'tags' => new TypeFieldDefinition('tags', 'tags'),
            'code' => new TypeFieldDefinition('code', exact: true),
        ], fullText: true);

        $lines = (new RecordTableSync($dm))->sync($def);

        $this->assertSame(['created table note', 'added index idx_note__updated_at', 'added full-text index ft_note_search'], $lines);
        $this->assertSame(['ft_note_search' => ['title', 'tags']], (new MysqlGrammar())->fullTextIndexes($this->pdo, 'note'));
        $this->assertSame('utf8mb4_bin', (new MysqlGrammar())->columnCollations($this->pdo, 'note')['code']);
        $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'Kartenspiel', 'tags' => ['PHP', 'Würfel'], 'code' => 'AbC']), validate: false);
        $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($def, ['uuid' => 'b', 'title' => 'Brettspiel', 'tags' => ['php'], 'code' => 'abc']), validate: false);

        $this->assertTrue($dm->canScore($def));
        foreach (['Kartenspiel', 'Würfel'] as $word) {
            $this->assertGreaterThan(0.0, $dm->scoreIn($def, $word, ['a', 'b'])['a'], "full-text search finds {$word}");
        }
        $ids = static fn (iterable $records): array => array_map(static fn ($r): string => (string) $r->getId(), [...$records]);
        $this->assertSame(['a'], $ids($dm->queryIn($def)->where('code', 'AbC')->get()->items()), '= on an exact field stays exact');
        $this->assertSame(['b'], $ids($dm->queryIn($def)->where('code', 'IN', ['abc'])->get()->items()));
        $this->assertSame(['a'], $ids($dm->queryIn($def)->whereAny([['field' => 'tags', 'operator' => 'HAS', 'value' => ['PHP']]])->get()->items()), 'HAS on tags stays exact');
        $this->assertSame(['b', 'a'], $ids($dm->queryIn($def)->orderBy('code', \Preflow\Data\SortDirection::Desc)->get()->items()), 'sorted by bytes: "abc" after "AbC"');
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
