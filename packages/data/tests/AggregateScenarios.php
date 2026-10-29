<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * Aggregate queries give the same rows on every storage: one fixture, exact rows per scenario.
 * The using test sets $this->dm with a driver named storage().
 *
 * The fixture: 12 answers of 3 members over 4 steps. a12 has no step; a04, a06 and a10 have no
 * seconds; a03 took 7.5 seconds. grade differs only in case ('a', 'A'); topic is translatable.
 * extra is a structured (non-relation) field: a01 and a02 share a map, a03 has another, a05 a
 * list, the rest none.
 */
trait AggregateScenarios
{
    protected DataManager $dm;

    /** The structured values; with a non-ASCII letter and a slash, which JSON text may escape. */
    private const EXTRA = [
        'a01' => ['extra' => ['hint' => true]],
        'a02' => ['extra' => ['hint' => true]],
        'a03' => ['extra' => ['note' => 'Rätsel/2']],
        'a05' => ['extra' => ['b', 'a']],
    ];

    abstract protected function storage(): string;

    protected function answers(): TypeDefinition
    {
        $answer = new TypeDefinition('answer', 'answer', $this->storage(), [
            'member' => new TypeFieldDefinition('member', 'relation', config: ['target' => 'member']),
            'step' => new TypeFieldDefinition('step'),
            'correct' => new TypeFieldDefinition('correct', 'boolean'),
            'tries' => new TypeFieldDefinition('tries', 'number'),
            'seconds' => new TypeFieldDefinition('seconds', 'number'),
            'grade' => new TypeFieldDefinition('grade'),
            'topic' => new TypeFieldDefinition('topic', translatable: true),
            'extra' => new TypeFieldDefinition('extra', 'json'),
        ], locales: new Locales(['de', 'en'], 'de'));
        (new RecordTableSync($this->dm))->sync($answer);

        $game = ['de' => 'Spiel', 'en' => 'Game'];
        $play = ['de' => 'Spiel', 'en' => 'Play'];
        $puzzle = ['de' => 'Rätsel', 'en' => 'Puzzle'];
        $riddle = ['de' => 'Rätsel', 'en' => 'Riddle'];
        foreach ([
            ['a01', 'm1', 's1', false, 2, 10, 'a', $game],
            ['a02', 'm1', 's2', true, 1, 5, 'A', $play],
            ['a03', 'm1', 's3', true, 1, 7.5, 'b', $puzzle],
            ['a04', 'm1', 's4', false, 3, null, 'a', $puzzle],
            ['a05', 'm2', 's1', true, 1, 4, 'a', $game],
            ['a06', 'm2', 's2', true, 2, null, 'A', $game],
            ['a07', 'm2', 's3', false, 4, 12, 'b', $riddle],
            ['a08', 'm2', 's4', true, 1, 8, 'a', $play],
            ['a09', 'm3', 's1', true, 1, 3, 'A', $game],
            ['a10', 'm3', 's2', false, 2, null, 'b', $puzzle],
            ['a11', 'm3', 's3', true, 1, 8, 'a', $game],
            ['a12', 'm3', null, true, 1, 1, 'a', $game],
        ] as [$uuid, $member, $step, $correct, $tries, $seconds, $grade, $topic]) {
            $row = ['uuid' => $uuid, 'member' => $member, 'correct' => $correct, 'tries' => $tries, 'seconds' => $seconds, 'grade' => $grade, 'topic' => $topic];
            if ($step !== null) {
                $row['step'] = $step;
            }
            $row += self::EXTRA[$uuid] ?? [];
            $this->dm->saveType(DynamicRecord::fromArray($answer, $row), false);
        }

        return $answer;
    }

    public function test_counts_per_group(): void
    {
        $rows = $this->dm->queryIn($this->answers())->aggregate()
            ->groupBy('member')
            ->count()
            ->sum('tries', 'tries')
            ->get();

        $this->assertSame([
            ['member' => 'm1', 'count' => 4, 'tries' => 7],
            ['member' => 'm2', 'count' => 4, 'tries' => 8],
            ['member' => 'm3', 'count' => 4, 'tries' => 5],
        ], $rows);
    }

    public function test_measures_skip_nulls(): void
    {
        $rows = $this->dm->queryIn($this->answers())->aggregate()
            ->groupBy('member')
            ->count()
            ->sum('seconds', 'total')
            ->avg('seconds', 'average')
            ->min('seconds', 'fastest')
            ->max('seconds', 'slowest')
            ->get();

        $this->assertSame([
            ['member' => 'm1', 'count' => 4, 'total' => 22.5, 'average' => 7.5, 'fastest' => 5, 'slowest' => 10],
            ['member' => 'm2', 'count' => 4, 'total' => 24, 'average' => 8, 'fastest' => 4, 'slowest' => 12],
            ['member' => 'm3', 'count' => 4, 'total' => 12, 'average' => 4, 'fastest' => 1, 'slowest' => 8],
        ], $rows);
    }

    /** A decimal minimum and maximum come back as they are (MariaDB once rounded 7.5 to 8). */
    public function test_decimal_min_and_max(): void
    {
        $answer = $this->answers();
        $rows = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('step')
            ->min('seconds', 'fastest')
            ->max('seconds', 'slowest')
            ->get();
        $this->assertSame([
            ['step' => null, 'fastest' => 1, 'slowest' => 1],
            ['step' => 's1', 'fastest' => 3, 'slowest' => 10],
            ['step' => 's2', 'fastest' => 5, 'slowest' => 5],
            ['step' => 's3', 'fastest' => 7.5, 'slowest' => 12],
            ['step' => 's4', 'fastest' => 8, 'slowest' => 8],
        ], $rows);

        $one = $this->dm->queryIn($answer)->where('member', 'm1')->where('step', 's3')->aggregate()
            ->min('seconds', 'fastest')
            ->max('seconds', 'slowest')
            ->get();
        $this->assertSame([['fastest' => 7.5, 'slowest' => 7.5]], $one);
    }

    public function test_avg_of_nothing_is_null_sum_is_zero(): void
    {
        $answer = $this->answers();
        $rows = $this->dm->queryIn($answer)->where('member', 'm3')->aggregate()
            ->groupBy('step')
            ->sum('seconds', 'total')
            ->avg('seconds', 'average')
            ->get();
        $this->assertSame([
            ['step' => null, 'total' => 1, 'average' => 1],
            ['step' => 's1', 'total' => 3, 'average' => 3],
            ['step' => 's2', 'total' => 0, 'average' => null],
            ['step' => 's3', 'total' => 8, 'average' => 8],
        ], $rows);

        $totals = $this->dm->queryIn($answer)->where('member', 'm2')->where('step', 's2')->aggregate()
            ->count()
            ->sum('seconds', 'total')
            ->avg('seconds', 'average')
            ->min('seconds', 'fastest')
            ->max('seconds', 'slowest')
            ->get();
        $this->assertSame([['count' => 1, 'total' => 0, 'average' => null, 'fastest' => null, 'slowest' => null]], $totals);
    }

    public function test_groups_missing_values_together(): void
    {
        $answer = $this->answers();
        $bySeconds = $this->dm->queryIn($answer)->aggregate()->groupBy('seconds')->count()->get();
        $this->assertSame([
            ['seconds' => null, 'count' => 3],
            ['seconds' => 1, 'count' => 1],
            ['seconds' => 3, 'count' => 1],
            ['seconds' => 4, 'count' => 1],
            ['seconds' => 5, 'count' => 1],
            ['seconds' => 7.5, 'count' => 1],
            ['seconds' => 8, 'count' => 2],
            ['seconds' => 10, 'count' => 1],
            ['seconds' => 12, 'count' => 1],
        ], $bySeconds, 'missing first, numbers in number order');

        $byStep = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('step')
            ->count()
            ->orderBy('step', SortDirection::Desc)
            ->get();
        $this->assertSame([
            ['step' => 's4', 'count' => 2],
            ['step' => 's3', 'count' => 3],
            ['step' => 's2', 'count' => 3],
            ['step' => 's1', 'count' => 3],
            ['step' => null, 'count' => 1],
        ], $byStep, 'missing last when descending');
    }

    public function test_having_filters_groups(): void
    {
        $answer = $this->answers();
        $bySum = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('member')
            ->sum('tries', 'total')
            ->having('total', '>', 5)
            ->get();
        $this->assertSame([['member' => 'm1', 'total' => 7], ['member' => 'm2', 'total' => 8]], $bySum);

        $byCount = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('step')
            ->count()
            ->having('count', '>=', 3)
            ->having('count', '<', 4)
            ->get();
        $this->assertSame([['step' => 's1', 'count' => 3], ['step' => 's2', 'count' => 3], ['step' => 's3', 'count' => 3]], $byCount);

        $byAverage = $this->dm->queryIn($answer)->where('member', 'm3')->aggregate()
            ->groupBy('step')
            ->avg('seconds', 'average')
            ->having('average', '>=', 0)
            ->get();
        $this->assertSame([
            ['step' => null, 'average' => 1],
            ['step' => 's1', 'average' => 3],
            ['step' => 's3', 'average' => 8],
        ], $byAverage, 'a missing average matches no comparison');
    }

    public function test_top_n_with_ties_is_stable(): void
    {
        $answer = $this->answers();
        $top = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('step')
            ->count()
            ->orderBy('count', SortDirection::Desc)
            ->limit(2)
            ->get();
        $this->assertSame([['step' => 's1', 'count' => 3], ['step' => 's2', 'count' => 3]], $top);

        $next = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('step')
            ->count()
            ->orderBy('count', SortDirection::Desc)
            ->limit(2)
            ->offset(1)
            ->get();
        $this->assertSame([['step' => 's2', 'count' => 3], ['step' => 's3', 'count' => 3]], $next);

        $rest = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('step')
            ->count()
            ->orderBy('count', SortDirection::Desc)
            ->offset(3)
            ->get();
        $this->assertSame([['step' => 's4', 'count' => 2], ['step' => null, 'count' => 1]], $rest, 'an offset without a limit');
    }

    public function test_count_distinct(): void
    {
        $answer = $this->answers();
        $perMember = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('member')
            ->countDistinct('step', 'steps')
            ->countDistinct('seconds', 'times')
            ->get();
        $this->assertSame([
            ['member' => 'm1', 'steps' => 4, 'times' => 3],
            ['member' => 'm2', 'steps' => 4, 'times' => 3],
            ['member' => 'm3', 'steps' => 3, 'times' => 3],
        ], $perMember, 'a missing value is no value');

        $totals = $this->dm->queryIn($answer)->aggregate()
            ->countDistinct('step', 'steps')
            ->countDistinct('seconds', 'times')
            ->countDistinct('grade', 'grades')
            ->get();
        $this->assertSame([['steps' => 4, 'times' => 8, 'grades' => 3]], $totals);
    }

    public function test_filters_before_grouping(): void
    {
        $rows = $this->dm->queryIn($this->answers())
            ->where('tries', '>=', 2)
            ->whereAny([['field' => 'member', 'operator' => 'IN', 'value' => ['m1', 'm3']]])
            ->aggregate()
            ->groupBy('member')
            ->count()
            ->sum('tries', 'tries')
            ->get();

        $this->assertSame([['member' => 'm1', 'count' => 2, 'tries' => 5], ['member' => 'm3', 'count' => 1, 'tries' => 2]], $rows);
    }

    public function test_translatable_group_uses_locale(): void
    {
        $answer = $this->answers();
        $de = $this->dm->queryIn($answer)->aggregate()->groupBy('topic')->count()->get();
        $en = $this->dm->queryIn($answer)->locale('en')->aggregate()->groupBy('topic')->count()->get();

        $this->assertSame([['topic' => 'Rätsel', 'count' => 4], ['topic' => 'Spiel', 'count' => 8]], $de);
        $this->assertSame([
            ['topic' => 'Game', 'count' => 6],
            ['topic' => 'Play', 'count' => 2],
            ['topic' => 'Puzzle', 'count' => 3],
            ['topic' => 'Riddle', 'count' => 1],
        ], $en);
    }

    public function test_two_group_fields(): void
    {
        $rows = $this->dm->queryIn($this->answers())->aggregate()
            ->groupBy('member', 'grade')
            ->count()
            ->get();

        $this->assertSame([
            ['member' => 'm1', 'grade' => 'A', 'count' => 1],
            ['member' => 'm1', 'grade' => 'a', 'count' => 2],
            ['member' => 'm1', 'grade' => 'b', 'count' => 1],
            ['member' => 'm2', 'grade' => 'A', 'count' => 1],
            ['member' => 'm2', 'grade' => 'a', 'count' => 2],
            ['member' => 'm2', 'grade' => 'b', 'count' => 1],
            ['member' => 'm3', 'grade' => 'A', 'count' => 1],
            ['member' => 'm3', 'grade' => 'a', 'count' => 2],
            ['member' => 'm3', 'grade' => 'b', 'count' => 1],
        ], $rows);
    }

    /** 'a' and 'A' are two groups, ordered by their bytes, on every storage. */
    public function test_text_groups_are_case_sensitive(): void
    {
        $answer = $this->answers();
        $rows = $this->dm->queryIn($answer)->aggregate()->groupBy('grade')->count()->get();
        $this->assertSame([
            ['grade' => 'A', 'count' => 3],
            ['grade' => 'a', 'count' => 6],
            ['grade' => 'b', 'count' => 3],
        ], $rows);

        $range = $this->dm->queryIn($answer)->where('grade', '!=', 'b')->aggregate()
            ->min('grade', 'lowest')
            ->max('grade', 'highest')
            ->get();
        $this->assertSame([['lowest' => 'A', 'highest' => 'a']], $range);
    }

    public function test_boolean_groups_come_back_as_bool(): void
    {
        $answer = $this->answers();
        $rows = $this->dm->queryIn($answer)->aggregate()->groupBy('correct')->count()->get();
        $this->assertSame([['correct' => false, 'count' => 4], ['correct' => true, 'count' => 8]], $rows);

        $range = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('member')
            ->min('correct', 'any_wrong')
            ->max('correct', 'any_right')
            ->get();
        $this->assertSame([
            ['member' => 'm1', 'any_wrong' => false, 'any_right' => true],
            ['member' => 'm2', 'any_wrong' => false, 'any_right' => true],
            ['member' => 'm3', 'any_wrong' => false, 'any_right' => true],
        ], $range);

        $right = $this->dm->queryIn($answer)->where('uuid', 'a02')->aggregate()->min('correct', 'all_right')->get();
        $this->assertSame([['all_right' => true]], $right);
    }

    /** A structured value is grouped and measured as the JSON text SQL stores it as. */
    public function test_structured_values_count_as_their_json_text(): void
    {
        $answer = $this->answers();
        $rows = $this->dm->queryIn($answer)->aggregate()->groupBy('extra')->count()->get();
        $this->assertSame([
            ['extra' => null, 'count' => 8],
            ['extra' => '["b","a"]', 'count' => 1],
            ['extra' => '{"hint":true}', 'count' => 2],
            ['extra' => '{"note":"Rätsel\\/2"}', 'count' => 1],
        ], $rows);

        $measures = $this->dm->queryIn($answer)->aggregate()
            ->groupBy('member')
            ->countDistinct('extra', 'kinds')
            ->min('extra', 'lowest')
            ->max('extra', 'highest')
            ->get();
        $this->assertSame([
            ['member' => 'm1', 'kinds' => 2, 'lowest' => '{"hint":true}', 'highest' => '{"note":"Rätsel\\/2"}'],
            ['member' => 'm2', 'kinds' => 1, 'lowest' => '["b","a"]', 'highest' => '["b","a"]'],
            ['member' => 'm3', 'kinds' => 0, 'lowest' => null, 'highest' => null],
        ], $measures);
    }

    public function test_empty_input_gives_no_rows(): void
    {
        $answer = $this->answers();
        $grouped = $this->dm->queryIn($answer)->where('member', 'nobody')->aggregate()->groupBy('member')->count()->get();
        $this->assertSame([], $grouped);

        $totals = $this->dm->queryIn($answer)->where('member', 'nobody')->aggregate()
            ->count()
            ->sum('tries', 'tries')
            ->avg('seconds', 'average')
            ->max('tries', 'most')
            ->get();
        $this->assertSame([['count' => 0, 'tries' => 0, 'average' => null, 'most' => null]], $totals, 'totals of nothing are one row');
    }

    public function test_later_builder_changes_do_not_reach_the_aggregate(): void
    {
        $builder = $this->dm->queryIn($this->answers())->where('member', 'm1');
        $aggregate = $builder->aggregate()->count();
        $builder->where('step', 's1');

        $this->assertSame([['count' => 4]], $aggregate->get());
        $this->assertSame([['count' => 1]], $builder->aggregate()->count()->get());
    }
}
