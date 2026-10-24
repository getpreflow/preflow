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

    public function test_numeric_order_by(): void
    {
        $q = (new Query())->orderBy('_order', numeric: true)->orderBy('uuid');
        [$sql] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" ORDER BY ("_order" + 0) ASC, "uuid" ASC', $sql);
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

    public function test_any_group_compiles_contains_in_and_equals(): void
    {
        $q = (new Query())->where('status', 'live')->whereAny([
            ['field' => 'title', 'operator' => 'CONTAINS', 'value' => '50%_!'],
            ['field' => 'id', 'operator' => 'IN', 'value' => ['a', 'b']],
            ['field' => 'kind', 'operator' => '=', 'value' => 'x'],
        ]);

        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame(
            'SELECT * FROM "posts" WHERE "status" = ? AND ("title" LIKE ? ESCAPE \'!\' OR "id" IN (?,?) OR "kind" = ?)',
            $sql,
        );
        $this->assertSame(['live', '%50!%!_!!%', 'a', 'b', 'x'], $bindings);
    }

    public function test_empty_in_matches_nothing_and_groups_apply_to_count(): void
    {
        $q = (new Query())->whereAny([['field' => 'id', 'operator' => 'IN', 'value' => []]])
            ->whereAny([['field' => 'title', 'operator' => 'CONTAINS', 'value' => 'a']]);

        [$sql, $bindings] = $this->compiler->compileCount('posts', $q);

        $this->assertSame(
            'SELECT COUNT(*) as total FROM "posts" WHERE (0 = 1) AND ("title" LIKE ? ESCAPE \'!\')',
            $sql,
        );
        $this->assertSame(['%a%'], $bindings);
    }

    public function test_sqlite_has_checks_plain_id_and_json_array(): void
    {
        $q = (new Query())->whereAny([['field' => 'tags', 'operator' => 'HAS', 'value' => ['a', 'b']]]);

        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame(
            'SELECT * FROM "posts" WHERE (("tags" IN (?,?) OR EXISTS (SELECT 1 FROM json_each(CASE WHEN json_valid("tags") THEN "tags" ELSE \'[]\' END) WHERE value IN (?,?))))',
            $sql,
        );
        $this->assertSame(['a', 'b', 'a', 'b'], $bindings);
    }

    public function test_mysql_has_uses_json_contains_per_id(): void
    {
        $compiler = new QueryCompiler(new \Preflow\Data\Driver\MysqlDialect());
        $q = (new Query())->whereAny([['field' => 'tags', 'operator' => 'HAS', 'value' => ['a', 'b']]]);

        [$sql, $bindings] = $compiler->compile('posts', $q);

        $this->assertSame(
            'SELECT * FROM `posts` WHERE ((`tags` IN (?,?) OR (CASE WHEN JSON_VALID(`tags`) THEN JSON_CONTAINS(`tags`, ?) ELSE 0 END) OR (CASE WHEN JSON_VALID(`tags`) THEN JSON_CONTAINS(`tags`, ?) ELSE 0 END)))',
            $sql,
        );
        $this->assertSame(['a', 'b', '"a"', '"b"'], $bindings);
    }

    public function test_any_group_on_translatable_column_uses_localization(): void
    {
        $q = (new Query())->whereAny([['field' => 'title', 'operator' => 'CONTAINS', 'value' => 'x']]);
        $q->localize(['title'], 'de', 'en');

        [$sql] = $this->compiler->compile('posts', $q);

        $this->assertStringContainsString("json_extract(\"title\", '$.\"de\"')", $sql);
        $this->assertStringContainsString("LIKE ? ESCAPE '!'", $sql);
    }

    public function test_empty_has_matches_nothing(): void
    {
        $q = (new Query())->whereAny([['field' => 'tags', 'operator' => 'HAS', 'value' => []]]);

        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" WHERE (0 = 1)', $sql);
        $this->assertSame([], $bindings);
    }
}
