<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\QueryCompiler;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class QueryCompilerTest extends TestCase
{
    private QueryCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new QueryCompiler();
    }

    public function test_empty_query(): void
    {
        [$sql, $bindings] = $this->compiler->compile('posts', new Query());

        $this->assertSame('SELECT * FROM "posts"', $sql);
        $this->assertSame([], $bindings);
    }

    public function test_where_equals(): void
    {
        $q = (new Query())->where('status', 'active');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" WHERE "status" = ?', $sql);
        $this->assertSame(['active'], $bindings);
    }

    public function test_multiple_wheres(): void
    {
        $q = (new Query())->where('status', 'active')->where('type', 'blog');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" WHERE "status" = ? AND "type" = ?', $sql);
        $this->assertSame(['active', 'blog'], $bindings);
    }

    public function test_or_where(): void
    {
        $q = (new Query())->where('status', 'active')->orWhere('status', 'pending');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" WHERE "status" = ? OR "status" = ?', $sql);
        $this->assertSame(['active', 'pending'], $bindings);
    }

    public function test_order_by(): void
    {
        $q = (new Query())->orderBy('created', SortDirection::Desc)->orderBy('title');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" ORDER BY "created" DESC, "title" ASC', $sql);
    }

    public function test_limit_offset(): void
    {
        $q = (new Query())->limit(10)->offset(20);
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" LIMIT 10 OFFSET 20', $sql);
    }

    public function test_search(): void
    {
        $q = (new Query())->search('hello', ['title', 'body']);
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertStringContainsString('LIKE', $sql);
        $this->assertStringContainsString('"title"', $sql);
        $this->assertStringContainsString('"body"', $sql);
        $this->assertContains('%hello%', $bindings);
    }

    public function test_like_operator(): void
    {
        $q = (new Query())->where('title', 'LIKE', '%test%');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" WHERE "title" LIKE ?', $sql);
        $this->assertSame(['%test%'], $bindings);
    }

    public function test_count_query(): void
    {
        $q = (new Query())->where('status', 'active');
        [$sql, $bindings] = $this->compiler->compileCount('posts', $q);

        $this->assertSame('SELECT COUNT(*) as total FROM "posts" WHERE "status" = ?', $sql);
        $this->assertSame(['active'], $bindings);
    }

    public function test_full_query(): void
    {
        $q = (new Query())
            ->where('status', 'active')
            ->orderBy('title')
            ->limit(5)
            ->offset(10);

        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame(
            'SELECT * FROM "posts" WHERE "status" = ? ORDER BY "title" ASC LIMIT 5 OFFSET 10',
            $sql
        );
    }

    public function test_uses_dialect_for_quoting(): void
    {
        $compiler = new QueryCompiler(new \Preflow\Data\Driver\MysqlDialect());
        $query = new Query();

        [$sql] = $compiler->compile('users', $query);

        $this->assertStringContainsString('`users`', $sql);
    }
}
