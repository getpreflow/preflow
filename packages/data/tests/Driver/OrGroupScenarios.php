<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use Preflow\Data\Query;
use Preflow\Data\StorageDriver;

/**
 * One set of OR-group checks run against every storage driver (SQLite, JSON, MySQL).
 * The using test class provides the table/driver and calls the check* methods.
 */
trait OrGroupScenarios
{
    /** @param list<array{string, string, string}> $rows uuid, title, tags */
    private function seedGroupRows(StorageDriver $d, array $rows): void
    {
        foreach ($rows as [$uuid, $title, $tags]) {
            $d->save('items', $uuid, ['uuid' => $uuid, 'title' => $title, 'tags' => $tags]);
        }
    }

    /** @return list<string> sorted uuids */
    private function groupIds(StorageDriver $d, Query $q): array
    {
        $ids = array_column($d->findMany('items', $q)->items(), 'uuid');
        sort($ids);
        return $ids;
    }

    private function checkContainsIsCaseInsensitive(StorageDriver $d): void
    {
        $this->seedGroupRows($d, [['1', 'Hello World', ''], ['2', 'other', ''], ['3', 'WORLDwide', '']]);
        $q = (new Query())->whereAny([['field' => 'title', 'operator' => 'CONTAINS', 'value' => 'world']]);
        $this->assertSame(['1', '3'], $this->groupIds($d, $q));
        $this->assertSame(2, $d->findMany('items', $q)->total());
    }

    private function checkContainsTakesSpecialCharsLiterally(StorageDriver $d): void
    {
        $this->seedGroupRows($d, [
            ['1', 'Sale 50% off', ''], ['2', 'Sale 500 off', ''], ['3', 'a_b', ''], ['4', 'axb', ''],
            ['5', 'wow!', ''], ['6', "it's", ''], ['7', 'wow', ''], ['8', 'its', ''],
        ]);
        $find = fn (string $w) => $this->groupIds($d, (new Query())->whereAny([['field' => 'title', 'operator' => 'CONTAINS', 'value' => $w]]));
        $this->assertSame(['1'], $find('50%'));
        $this->assertSame(['3'], $find('a_b'));
        $this->assertSame(['5'], $find('!'));
        $this->assertSame(['6'], $find("'"));
    }

    private function checkContainsFindsUmlauts(StorageDriver $d): void
    {
        $this->seedGroupRows($d, [['1', 'Würfelspiel', ''], ['2', 'Wuerfel', ''], ['3', 'Kartenspiel', '']]);
        $q = (new Query())->whereAny([['field' => 'title', 'operator' => 'CONTAINS', 'value' => 'würfel']]);
        $this->assertSame(['1'], $this->groupIds($d, $q));
    }

    private function checkIn(StorageDriver $d): void
    {
        $this->seedGroupRows($d, [['1', 'a', ''], ['2', 'b', ''], ['3', 'c', ''], ['4', '10', '']]);
        $in = fn (array $v) => $this->groupIds($d, (new Query())->whereAny([['field' => 'title', 'operator' => 'IN', 'value' => $v]]));
        $this->assertSame(['1', '3'], $in(['a', 'c', 'zzz']));
        $this->assertSame([], $in(['1e1', '010']), 'compared as strings, not as numbers');
        $this->assertSame(['4'], $in(['10']));
        $this->assertSame([], $in([]));
        $this->assertSame(['2'], $in([...array_map(static fn (int $i): string => 'n' . $i, range(1, 3000)), 'b']), 'a long id list');
    }

    private function checkHas(StorageDriver $d): void
    {
        $this->seedGroupRows($d, [
            ['1', 'x', '["a","b"]'], ['2', 'x', 'a'], ['3', 'x', ''], ['4', 'x', '{not json'],
            ['5', 'x', '["c"]'], ['6', 'x', 'ab'],
        ]);
        $has = fn (array $v) => $this->groupIds($d, (new Query())->whereAny([['field' => 'tags', 'operator' => 'HAS', 'value' => $v]]));
        $this->assertSame(['1', '2'], $has(['a']));
        $this->assertSame(['1', '2', '5'], $has(['b', 'c', 'a']));
        $this->assertSame(['5'], $has(['c']));
        $this->assertSame([], $has(['']), 'empty storage is not a member');
        $this->assertSame([], $has(['x']), 'invalid JSON rows never error');
        $this->assertSame([], $has([]));
        $this->assertSame([], $has(['zzz']));
        $many = array_map(static fn (int $i): string => 'n' . $i, range(1, 3000));
        $this->assertSame(['1', '2', '5'], $has([...$many, 'c', 'a']), 'a long id list');
    }

    private function checkOrWithinAndAcrossGroups(StorageDriver $d): void
    {
        $this->seedGroupRows($d, [
            ['1', 'red apple', '["a"]'], ['2', 'green apple', '["b"]'], ['3', 'red pear', '["b"]'], ['4', 'blue', '["a"]'],
        ]);
        $q = (new Query())
            ->whereAny([
                ['field' => 'title', 'operator' => 'CONTAINS', 'value' => 'red'],
                ['field' => 'title', 'operator' => '=', 'value' => 'blue'],
            ])
            ->whereAny([['field' => 'tags', 'operator' => 'HAS', 'value' => ['a']]])
            ->where('title', '!=', 'nothing');
        $this->assertSame(['1', '4'], $this->groupIds($d, $q));
        $this->assertSame(2, $d->findMany('items', $q)->total());
        $this->assertSame(2, count($q->getAnyGroups()));
    }

    private function checkCountEqualsRowsWithLimit(StorageDriver $d): void
    {
        $this->seedGroupRows($d, [['1', 'cat', ''], ['2', 'cat', ''], ['3', 'cat', '']]);
        $q = (new Query())->whereAny([['field' => 'title', 'operator' => 'CONTAINS', 'value' => 'cat']])->limit(2);
        $r = $d->findMany('items', $q);
        $this->assertCount(2, $r->items());
        $this->assertSame(3, $r->total());
    }

    private function checkOrWhereChainIsGroupedBeforeGroupsAreAnded(StorageDriver $d): void
    {
        $this->seedGroupRows($d, [['1', 'x', 'k'], ['2', 'y', 'k'], ['3', 'x', 'z'], ['4', 'y', 'z']]);
        $q = (new Query())
            ->where('title', 'x')->orWhere('title', 'y')
            ->whereAny([['field' => 'tags', 'operator' => '=', 'value' => 'k']]);
        $this->assertSame(['1', '2'], $this->groupIds($d, $q));
        $this->assertSame(2, $d->findMany('items', $q)->total());
    }
}
