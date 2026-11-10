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

    public function test_where_any_collects_groups(): void
    {
        $c = [['field' => 'a', 'operator' => 'CONTAINS', 'value' => 'x'], ['field' => 'b', 'operator' => '=', 'value' => 1]];
        $q = (new Query())->whereAny($c)->whereAny([$c[0]]);

        $this->assertSame([$c, [$c[0]]], $q->getAnyGroups());
        $this->assertSame([], (new Query())->getAnyGroups());
    }

    public function test_where_any_ignores_empty_groups_and_rejects_unknown_operators(): void
    {
        $this->assertSame([], (new Query())->whereAny([])->getAnyGroups());

        $this->expectException(\InvalidArgumentException::class);
        (new Query())->whereAny([['field' => 'a', 'operator' => 'DROP', 'value' => 1]]);
    }

    public function test_where_rejects_an_injected_operator(): void
    {
        foreach (['where', 'orWhere'] as $method) {
            try {
                (new Query())->{$method}('status', '= 1 OR 1=1 --', 'x');
                $this->fail("{$method}() took an injected operator");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('= 1 OR 1=1 --', $e->getMessage());
            }
        }
    }

    public function test_where_rejects_operators_outside_the_whitelist(): void
    {
        foreach (['DROP', '==', 'IS', 'REGEXP', ';', '', 'LIKE; --'] as $operator) {
            try {
                (new Query())->where('status', $operator, 'x');
                $this->fail("where() took operator \"{$operator}\"");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->where('status', ['='], 'x');
    }

    public function test_where_takes_the_whitelisted_operators_in_any_case_and_stores_them_upper_case(): void
    {
        $cases = [
            '=' => '=', '!=' => '!=', '<>' => '<>', '<' => '<', '>' => '>', '<=' => '<=', '>=' => '>=',
            'like' => 'LIKE', 'Like' => 'LIKE', 'not like' => 'NOT LIKE', 'NOT  LIKE' => 'NOT LIKE',
            'in' => 'IN', 'not in' => 'NOT IN',
        ];
        foreach ($cases as $given => $stored) {
            $value = in_array($stored, ['IN', 'NOT IN'], true) ? ['a'] : 'a';
            $this->assertSame($stored, (new Query())->where('status', $given, $value)->getWheres()[0]['operator'], $given);
        }
    }

    public function test_in_and_not_in_need_a_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->where('status', 'IN', 'a');
    }

    public function test_the_two_argument_form_takes_any_value_as_the_compared_value(): void
    {
        $w = (new Query())->where('status', '> 1 OR 1=1')->getWheres()[0];
        $this->assertSame('=', $w['operator']);
        $this->assertSame('> 1 OR 1=1', $w['value']);
    }

    public function test_where_null_compares_with_null_in_both_forms(): void
    {
        $two = (new Query())->where('deleted', null)->getWheres()[0];
        $three = (new Query())->where('deleted', '=', null)->getWheres()[0];
        $or = (new Query())->where('a', 1)->orWhere('deleted', '!=', null)->getWheres()[1];

        $this->assertSame(['=', null], [$two['operator'], $two['value']]);
        $this->assertSame(['=', null], [$three['operator'], $three['value']]);
        $this->assertSame(['!=', null], [$or['operator'], $or['value']]);
    }

    public function test_field_names_must_be_identifiers(): void
    {
        $calls = [
            'where' => fn (string $f) => (new Query())->where($f, 'x'),
            'orWhere' => fn (string $f) => (new Query())->orWhere($f, '=', 'x'),
            'orderBy' => fn (string $f) => (new Query())->orderBy($f),
            'whereAny' => fn (string $f) => (new Query())->whereAny([['field' => $f, 'operator' => '=', 'value' => 'x']]),
            'search' => fn (string $f) => (new Query())->search('x', [$f]),
        ];
        foreach ($calls as $name => $call) {
            foreach (['title" OR 1=1 --', 'title` OR 1=1 --', 'data.title', 'my-field', '1st', '', 'a b'] as $field) {
                try {
                    $call($field);
                    $this->fail("{$name}() took field \"{$field}\"");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString('field', strtolower($e->getMessage()));
                }
            }
            $call('_published_at');
            $call('Title2');
        }
    }

    public function test_the_message_shows_the_bad_value_escaped(): void
    {
        try {
            (new Query())->where("a\"\nb", 'x');
            $this->fail('took the field');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertStringContainsString('a\"\nb', $e->getMessage());
        }
    }
}
