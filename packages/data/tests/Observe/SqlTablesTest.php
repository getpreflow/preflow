<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Observe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Observe\SqlTables;

final class SqlTablesTest extends TestCase
{
    /** @return iterable<string, array{string, ?list<string>}> */
    public static function statements(): iterable
    {
        yield 'holz catalog join' => [
            "SELECT o.*, m.name FROM `object__published` o JOIN `member` m ON m.uuid = o.member WHERE o.state = 'from x'",
            ['object__published', 'member'],
        ];
        yield 'update join' => ['UPDATE x JOIN y ON x.a = y.a SET x.b = 1', ['x', 'y']];
        yield 'cte' => ['WITH t AS (SELECT * FROM a) SELECT * FROM t', null];
        yield 'recursive cte' => ['WITH RECURSIVE t(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM t) SELECT n FROM t', null];
        yield 'comma list with aliases' => ['SELECT * FROM a, b AS bb, `c` WHERE 1', ['a', 'b', 'c']];
        yield 'derived table and comma' => ['SELECT * FROM (SELECT id FROM a) t, b', ['b', 'a']];
        yield 'subquery in where' => ['SELECT COUNT(*) FROM a WHERE EXISTS (SELECT 1 FROM b WHERE b.a = a.id)', ['a', 'b']];
        yield 'join group is unsure' => ['SELECT * FROM a LEFT JOIN (b JOIN c ON 1) ON 1', null];
        yield 'upsert' => ['INSERT INTO likes (a, b) VALUES (?, ?) ON DUPLICATE KEY UPDATE a = a + 1', ['likes']];
        yield 'sqlite upsert' => ['INSERT OR REPLACE INTO "x" (a) VALUES (1) ON CONFLICT(a) DO UPDATE SET a = 2', ['x']];
        yield 'insert select' => ['INSERT INTO t SELECT * FROM s', ['t', 's']];
        yield 'delete with subquery' => ['DELETE FROM likes WHERE id IN (SELECT id FROM gone)', ['likes', 'gone']];
        yield 'table-valued function' => ['SELECT * FROM a, json_each(a.tags) j', ['a']];
        yield 'schema prefix, for update' => ['SELECT * FROM main.Items FOR UPDATE', ['items']];
        yield 'truncate' => ['TRUNCATE likes', ['likes']];
        yield 'comments and strings' => ["select * from A -- from b\n where c = 'join d' /* join e */", ['a']];
        yield 'with rollup is no cte' => ['SELECT a, COUNT(*) FROM t GROUP BY a WITH ROLLUP', ['t']];
        yield 'no table' => ['SELECT 1', []];
        yield 'procedure is unsure' => ['CALL refresh_all()', null];
    }

    /** @param list<string>|null $tables */
    #[DataProvider('statements')]
    public function test_tables_of_a_statement(string $sql, ?array $tables): void
    {
        $this->assertSame($tables, SqlTables::of($sql));
    }

    public function test_reads_and_writes(): void
    {
        $this->assertFalse(SqlTables::writes('SELECT * FROM a'));
        $this->assertFalse(SqlTables::writes('WITH t AS (SELECT 1) SELECT * FROM t'));
        $this->assertFalse(SqlTables::writes("SELECT REPLACE(name, 'a', 'b') FROM a FOR UPDATE"));
        $this->assertTrue(SqlTables::writes('UPDATE a SET b = 1'));
        $this->assertTrue(SqlTables::writes('WITH t AS (SELECT 1) DELETE FROM a'));
        $this->assertTrue(SqlTables::writes('CALL refresh_all()'));
        $this->assertTrue(SqlTables::writes('select 1 into @x'));
    }
}
