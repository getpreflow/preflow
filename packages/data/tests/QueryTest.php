<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class QueryTest extends TestCase
{
    public function test_where_adds_condition(): void
    {
        $q = (new Query())->where('status', '=', 'active');

        $conditions = $q->getWheres();
        $this->assertCount(1, $conditions);
        $this->assertSame('status', $conditions[0]['field']);
        $this->assertSame('=', $conditions[0]['operator']);
        $this->assertSame('active', $conditions[0]['value']);
        $this->assertSame('AND', $conditions[0]['boolean']);
    }

    public function test_where_shorthand_equals(): void
    {
        $q = (new Query())->where('status', 'active');

        $conditions = $q->getWheres();
        $this->assertSame('=', $conditions[0]['operator']);
        $this->assertSame('active', $conditions[0]['value']);
    }

    public function test_or_where(): void
    {
        $q = (new Query())
            ->where('status', 'active')
            ->orWhere('status', 'pending');

        $conditions = $q->getWheres();
        $this->assertCount(2, $conditions);
        $this->assertSame('OR', $conditions[1]['boolean']);
    }

    public function test_order_by(): void
    {
        $q = (new Query())
            ->orderBy('created', SortDirection::Desc)
            ->orderBy('title');

        $orders = $q->getOrderBy();
        $this->assertCount(2, $orders);
        $this->assertSame('created', $orders[0]['field']);
        $this->assertSame(SortDirection::Desc, $orders[0]['direction']);
        $this->assertSame(SortDirection::Asc, $orders[1]['direction']);
    }

    public function test_limit_and_offset(): void
    {
        $q = (new Query())->limit(10)->offset(20);

        $this->assertSame(10, $q->getLimit());
        $this->assertSame(20, $q->getOffset());
    }

    public function test_search(): void
    {
        $q = (new Query())->search('hello world', ['title', 'body']);

        $this->assertSame('hello world', $q->getSearchTerm());
        $this->assertSame(['title', 'body'], $q->getSearchFields());
    }

    public function test_chaining(): void
    {
        $q = (new Query())
            ->where('status', 'active')
            ->orderBy('title')
            ->limit(5)
            ->offset(0);

        $this->assertCount(1, $q->getWheres());
        $this->assertCount(1, $q->getOrderBy());
        $this->assertSame(5, $q->getLimit());
        $this->assertSame(0, $q->getOffset());
    }

    public function test_defaults(): void
    {
        $q = new Query();

        $this->assertSame([], $q->getWheres());
        $this->assertSame([], $q->getOrderBy());
        $this->assertNull($q->getLimit());
        $this->assertNull($q->getOffset());
        $this->assertNull($q->getSearchTerm());
        $this->assertSame([], $q->getSearchFields());
    }
}
