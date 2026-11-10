<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use Preflow\Data\Query;
use Preflow\Data\StorageDriver;

/**
 * where() checks run against every storage driver (SQLite, JSON, MySQL), so the drivers agree
 * on null, IN / NOT IN, LIKE / NOT LIKE and <>. The using test class provides an `items` table
 * (uuid, title, tags) and calls the check* methods.
 */
trait WhereScenarios
{
    private function seedWhereRows(StorageDriver $d): void
    {
        $d->save('items', '1', ['uuid' => '1', 'title' => 'Apple', 'tags' => null]);
        $d->save('items', '2', ['uuid' => '2', 'title' => 'Banana', 'tags' => 'x']);
        $d->save('items', '3', ['uuid' => '3', 'title' => 'Cherry', 'tags' => 'y']);
    }

    /** @return list<string> sorted uuids */
    private function whereIds(StorageDriver $d, Query $q): array
    {
        $ids = array_map('strval', array_column($d->findMany('items', $q)->items(), 'uuid'));
        sort($ids);

        return $ids;
    }

    private function checkWhereNullFindsTheNullRow(StorageDriver $d): void
    {
        $this->seedWhereRows($d);

        $this->assertSame(['1'], $this->whereIds($d, (new Query())->where('tags', null)));
        $this->assertSame(['1'], $this->whereIds($d, (new Query())->where('tags', '=', null)));
        $this->assertSame(['2', '3'], $this->whereIds($d, (new Query())->where('tags', '!=', null)));
        $this->assertSame(['2', '3'], $this->whereIds($d, (new Query())->where('tags', '<>', null)));
        $this->assertSame(['1', '3'], $this->whereIds($d, (new Query())->where('title', 'Cherry')->orWhere('tags', null)));
        $this->assertSame(1, $d->findMany('items', (new Query())->where('tags', null))->total());
    }

    private function checkWhereInAndNotIn(StorageDriver $d): void
    {
        $this->seedWhereRows($d);

        $this->assertSame(['1', '3'], $this->whereIds($d, (new Query())->where('title', 'IN', ['Apple', 'Cherry'])));
        $this->assertSame(['1', '3'], $this->whereIds($d, (new Query())->where('title', 'in', ['Apple', 'Cherry'])));
        $this->assertSame(['2'], $this->whereIds($d, (new Query())->where('title', 'NOT IN', ['Apple', 'Cherry'])));
        $this->assertSame([], $this->whereIds($d, (new Query())->where('title', 'IN', [])));
        $this->assertSame(['1', '2', '3'], $this->whereIds($d, (new Query())->where('title', 'NOT IN', [])));
        $this->assertSame(['1', '2', '3'], $this->whereIds($d, (new Query())->where('tags', 'NOT IN', [])), 'an empty NOT IN takes null fields too');
        $this->assertSame(['3'], $this->whereIds($d, (new Query())->where('tags', 'NOT IN', ['x'])), 'a NOT IN never takes a null field');
    }

    private function checkWhereLikeNotLikeAndAngleNotEquals(StorageDriver $d): void
    {
        $this->seedWhereRows($d);

        $this->assertSame(['2'], $this->whereIds($d, (new Query())->where('title', 'like', 'b%')));
        $this->assertSame(['2', '3'], $this->whereIds($d, (new Query())->where('title', 'NOT LIKE', 'a%')));
        $this->assertSame(['1', '3'], $this->whereIds($d, (new Query())->where('title', '<>', 'Banana')));
    }
}
