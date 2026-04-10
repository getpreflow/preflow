<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\ResultSet;
use Preflow\Data\PaginatedResult;

final class ResultSetTest extends TestCase
{
    public function test_items_returns_data(): void
    {
        $rs = new ResultSet([['id' => '1'], ['id' => '2']]);

        $this->assertCount(2, $rs->items());
        $this->assertSame('1', $rs->items()[0]['id']);
    }

    public function test_total_returns_count(): void
    {
        $rs = new ResultSet([['a'], ['b'], ['c']], total: 3);

        $this->assertSame(3, $rs->total());
    }

    public function test_total_defaults_to_items_count(): void
    {
        $rs = new ResultSet([['a'], ['b']]);

        $this->assertSame(2, $rs->total());
    }

    public function test_first_returns_first_item(): void
    {
        $rs = new ResultSet([['id' => '1'], ['id' => '2']]);

        $this->assertSame(['id' => '1'], $rs->first());
    }

    public function test_first_returns_null_for_empty(): void
    {
        $rs = new ResultSet([]);

        $this->assertNull($rs->first());
    }

    public function test_map_transforms_items(): void
    {
        $rs = new ResultSet([['id' => '1'], ['id' => '2']]);

        $mapped = $rs->map(fn (array $item) => $item['id']);

        $this->assertSame(['1', '2'], $mapped->items());
    }

    public function test_count_interface(): void
    {
        $rs = new ResultSet([['a'], ['b']]);

        $this->assertCount(2, $rs);
    }

    public function test_iterable(): void
    {
        $rs = new ResultSet([['id' => '1'], ['id' => '2']]);
        $ids = [];

        foreach ($rs as $item) {
            $ids[] = $item['id'];
        }

        $this->assertSame(['1', '2'], $ids);
    }

    public function test_paginate(): void
    {
        $items = array_map(fn ($i) => ['id' => (string)$i], range(1, 25));
        $rs = new ResultSet($items, total: 25);

        $page = $rs->paginate(perPage: 10, currentPage: 2);

        $this->assertInstanceOf(PaginatedResult::class, $page);
        $this->assertSame(25, $page->total);
        $this->assertSame(10, $page->perPage);
        $this->assertSame(2, $page->currentPage);
        $this->assertSame(3, $page->lastPage);
        $this->assertTrue($page->hasMore);
    }

    public function test_paginate_last_page(): void
    {
        $rs = new ResultSet([['a']], total: 21);

        $page = $rs->paginate(perPage: 10, currentPage: 3);

        $this->assertSame(3, $page->lastPage);
        $this->assertFalse($page->hasMore);
    }

    public function test_empty_result_set(): void
    {
        $rs = new ResultSet([]);

        $this->assertSame(0, $rs->total());
        $this->assertNull($rs->first());
        $this->assertCount(0, $rs);
    }
}
