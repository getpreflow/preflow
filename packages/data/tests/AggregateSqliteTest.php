<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Model;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

#[Entity(table: 'attempt', storage: 'sqlite')]
final class AggregateAttempt extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $member = '';

    #[Field]
    public ?int $tries = null;
}

/** Aggregate queries compiled to SQL and run on SQLite. */
final class AggregateSqliteTest extends TestCase
{
    private \PDO $pdo;
    private DataManager $dm;
    private TypeDefinition $answer;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->dm = new DataManager(['sqlite' => new SqliteDriver($this->pdo)], 'sqlite');
        $this->answer = new TypeDefinition('answer', 'answer', 'sqlite', [
            'member' => new TypeFieldDefinition('member', 'relation', config: ['target' => 'member']),
            'step' => new TypeFieldDefinition('step'),
            'correct' => new TypeFieldDefinition('correct', 'boolean'),
            'tries' => new TypeFieldDefinition('tries', 'number'),
            'seconds' => new TypeFieldDefinition('seconds', 'number'),
        ]);
        (new RecordTableSync($this->dm))->sync($this->answer);

        foreach ([
            ['uuid' => 'a1', 'member' => 'm1', 'step' => 's1', 'correct' => false, 'tries' => 2, 'seconds' => 10],
            ['uuid' => 'a2', 'member' => 'm1', 'step' => 's1', 'correct' => true, 'tries' => 1, 'seconds' => 5],
            ['uuid' => 'a3', 'member' => 'm1', 'step' => 's2', 'correct' => true, 'tries' => 1, 'seconds' => 7.5],
            ['uuid' => 'a4', 'member' => 'm2', 'step' => 's1', 'correct' => false, 'tries' => 3, 'seconds' => null],
            ['uuid' => 'a5', 'member' => 'm2', 'step' => 's2', 'correct' => true, 'tries' => null, 'seconds' => 4],
        ] as $row) {
            $this->dm->saveType(DynamicRecord::fromArray($this->answer, $row), false);
        }
    }

    public function test_count_per_group(): void
    {
        $rows = $this->dm->queryIn($this->answer)->aggregate()
            ->groupBy('member')
            ->count()
            ->countDistinct('step', 'steps')
            ->get();

        $this->assertSame([
            ['member' => 'm1', 'count' => 3, 'steps' => 2],
            ['member' => 'm2', 'count' => 2, 'steps' => 2],
        ], $rows);
    }

    public function test_sum_avg_min_max(): void
    {
        $rows = $this->dm->queryIn($this->answer)->aggregate()
            ->groupBy('step')
            ->sum('tries', 'tries')
            ->avg('seconds', 'avg_seconds')
            ->min('tries', 'min_tries')
            ->max('seconds', 'max_seconds')
            ->get();

        $this->assertSame([
            ['step' => 's1', 'tries' => 6, 'avg_seconds' => 7.5, 'min_tries' => 1, 'max_seconds' => 10],
            ['step' => 's2', 'tries' => 1, 'avg_seconds' => 5.75, 'min_tries' => 1, 'max_seconds' => 7.5],
        ], $rows);
    }

    public function test_having_and_order_by_alias(): void
    {
        $ordered = $this->dm->queryIn($this->answer)->aggregate()
            ->groupBy('member')
            ->sum('tries', 'total')
            ->orderBy('total')
            ->get();
        $this->assertSame([['member' => 'm2', 'total' => 3], ['member' => 'm1', 'total' => 4]], $ordered);

        $filtered = $this->dm->queryIn($this->answer)->aggregate()
            ->groupBy('member')
            ->sum('tries', 'total')
            ->having('total', '>', 3)
            ->get();
        $this->assertSame([['member' => 'm1', 'total' => 4]], $filtered);

        $paged = $this->dm->queryIn($this->answer)->aggregate()
            ->groupBy('step', 'member')
            ->count()
            ->orderBy('count', SortDirection::Desc)
            ->limit(2)
            ->offset(1)
            ->get();
        $this->assertSame([
            ['step' => 's1', 'member' => 'm2', 'count' => 1],
            ['step' => 's2', 'member' => 'm1', 'count' => 1],
        ], $paged, 'ties ordered by the group fields');
    }

    public function test_where_filters_before_grouping(): void
    {
        $rows = $this->dm->queryIn($this->answer)
            ->where('step', 's1')
            ->whereAny([['field' => 'member', 'operator' => 'IN', 'value' => ['m1', 'm2']]])
            ->aggregate()
            ->groupBy('member')
            ->count()
            ->get();

        $this->assertSame([['member' => 'm1', 'count' => 2], ['member' => 'm2', 'count' => 1]], $rows);
    }

    public function test_totals_without_group_by(): void
    {
        $totals = $this->dm->queryIn($this->answer)->aggregate()
            ->count()
            ->sum('tries', 'tries')
            ->avg('seconds', 'avg_seconds')
            ->get();
        $this->assertSame([['count' => 5, 'tries' => 7, 'avg_seconds' => 6.625]], $totals);

        $empty = $this->dm->queryIn($this->answer)->where('member', 'nobody')->aggregate()
            ->count()
            ->sum('tries', 'tries')
            ->avg('seconds', 'avg_seconds')
            ->max('tries', 'max_tries')
            ->get();
        $this->assertSame([['count' => 0, 'tries' => 0, 'avg_seconds' => null, 'max_tries' => null]], $empty);
    }

    public function test_sum_casts_text_column(): void
    {
        $this->pdo->exec('CREATE TABLE score (uuid TEXT PRIMARY KEY, team TEXT, points TEXT)');
        $this->pdo->exec("INSERT INTO score VALUES ('a', 't', '3'), ('b', 't', '4'), ('c', 't', ''), ('d', 't', NULL), ('e', 't', '10')");
        $score = new TypeDefinition('score', 'score', 'sqlite', [
            'team' => new TypeFieldDefinition('team'),
            'points' => new TypeFieldDefinition('points', 'number'),
        ]);

        $rows = $this->dm->queryIn($score)->where('uuid', '!=', 'e')->aggregate()
            ->sum('points', 'total')
            ->avg('points', 'average')
            ->countDistinct('points', 'distinct')
            ->get();
        $this->assertSame([['total' => 7, 'average' => 3.5, 'distinct' => 2]], $rows, "'' and NULL are no values");

        $max = $this->dm->queryIn($score)->aggregate()->max('points', 'max')->get();
        $this->assertSame([['max' => 10]], $max, 'compared as numbers, not as text');
    }

    public function test_model_class_entry_point(): void
    {
        $this->pdo->exec('CREATE TABLE attempt (uuid TEXT PRIMARY KEY, member TEXT, tries INTEGER)');
        foreach ([['a', 'm1', 2], ['b', 'm1', 3], ['c', 'm2', 1]] as [$uuid, $member, $tries]) {
            $attempt = new AggregateAttempt();
            $attempt->uuid = $uuid;
            $attempt->member = $member;
            $attempt->tries = $tries;
            $this->dm->save($attempt, false);
        }

        $rows = $this->dm->query(AggregateAttempt::class)->aggregate()
            ->groupBy('member')
            ->sum('tries', 'total')
            ->orderBy('member', SortDirection::Desc)
            ->get();

        $this->assertSame([['member' => 'm2', 'total' => 1], ['member' => 'm1', 'total' => 5]], $rows);
    }

    public function test_translatable_group_uses_the_query_locale(): void
    {
        $topic = new TypeDefinition('topic', 'topic', 'sqlite', [
            'name' => new TypeFieldDefinition('name', translatable: true),
        ], locales: new Locales(['de', 'en'], 'de'));
        (new RecordTableSync($this->dm))->sync($topic);
        $this->dm->saveType(DynamicRecord::fromArray($topic, ['uuid' => 't1', 'name' => ['de' => 'Spiel', 'en' => 'Game']]), false);
        $this->dm->saveType(DynamicRecord::fromArray($topic, ['uuid' => 't2', 'name' => ['de' => 'Spiel', 'en' => 'Play']]), false);

        $de = $this->dm->queryIn($topic)->aggregate()->groupBy('name')->count()->get();
        $en = $this->dm->queryIn($topic)->locale('en')->aggregate()->groupBy('name')->count()->get();

        $this->assertSame([['name' => 'Spiel', 'count' => 2]], $de);
        $this->assertSame([['name' => 'Game', 'count' => 1], ['name' => 'Play', 'count' => 1]], $en);
    }
}
