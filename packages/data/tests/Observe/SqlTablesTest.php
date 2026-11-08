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
        yield 'mysql insert without into' => ['INSERT member (number, company) VALUES (?, ?)', ['member']];
        yield 'insert ignore set' => ['INSERT IGNORE member SET a = 1', ['member']];
        yield 'insert modifiers, quoted' => ['INSERT LOW_PRIORITY IGNORE `member` (a) VALUES (1)', ['member']];
        yield 'replace without into' => ['REPLACE member VALUES (1)', ['member']];
        yield 'replace into' => ['REPLACE INTO member VALUES (1)', ['member']];
        yield 'sqlite insert or ignore' => ['INSERT OR IGNORE INTO x (a) VALUES (1)', ['x']];
        yield 'insert naming no table is unsure' => ['INSERT VALUES (1)', null];
        yield 'update naming no table is unsure' => ['UPDATE SET a = 1', null];
        yield 'straight join' => ['SELECT * FROM a STRAIGHT_JOIN b ON a.id = b.id', ['a', 'b']];
        yield 'index hint then comma' => ['SELECT * FROM a FORCE INDEX (i1), b', ['a', 'b']];
        yield 'index hint for order by' => ['SELECT * FROM a x USE INDEX FOR ORDER BY (i1) JOIN c ON 1', ['a', 'c']];
        yield 'sqlite index hints' => ['SELECT * FROM a INDEXED BY i, b NOT INDEXED, c', ['a', 'b', 'c']];
        yield 'backslash in a string is unsure' => ["SELECT * FROM a WHERE x = 'C:\\' UNION SELECT * FROM b WHERE y = ''", null];
        yield 'backslash in double quotes is unsure' => ['SELECT * FROM a WHERE x = "a\\" FROM"', null];
        yield 'statements one by one' => ['SELECT 1; TRUNCATE likes', ['likes']];
        yield 'index ddl names no table' => ['CREATE INDEX i ON t (a)', []];
        yield 'executable comment is unsure' => ['/*!40000 UPDATE t SET a = 1 */', null];
        yield 'mariadb executable comment is unsure' => ['SELECT 1 /*M!100100 , (SELECT 1 FROM t) */', null];
        yield 'comma after join on' => ['SELECT * FROM a JOIN b ON a.x = b.x, c', ['a', 'b', 'c']];
        yield 'comma after join using' => ['SELECT * FROM a JOIN b USING (id), c', ['a', 'b', 'c']];
        yield 'comma after outer join, alias' => ['SELECT * FROM a LEFT OUTER JOIN b ON 1, c AS z WHERE z.q IN (1, 2)', ['a', 'b', 'c']];
        yield 'comma after join in a subquery' => ['SELECT * FROM t WHERE id IN (SELECT a.id FROM a JOIN b ON a.id = b.id, c)', ['t', 'a', 'b', 'c']];
        yield 'commas in on are not tables' => ['SELECT * FROM a JOIN b ON a.x = COALESCE(b.x, 1), c ORDER BY a.x, b.x', ['a', 'b', 'c']];
        yield 'update join then comma' => ['UPDATE a JOIN b ON a.id = b.id, c SET a.x = 1, c.y = 2', ['a', 'b', 'c']];
        yield 'commas after the from clause are no tables' => ['SELECT a.x, b.y FROM a JOIN b ON 1 GROUP BY a.x, b.y HAVING 1 LIMIT 1, 2', ['a', 'b']];
        yield 'mysql upsert after a select is no table' => ['INSERT INTO t SELECT * FROM s JOIN r ON 1 ON DUPLICATE KEY UPDATE a = 1, b = 2', ['t', 's', 'r']];
        yield 'upsert after a select is no table' => ['INSERT INTO t SELECT * FROM s JOIN r ON 1 ON CONFLICT(id) DO UPDATE SET a = 1, b = 2', ['t', 's', 'r']];
        yield 'optimizer hint in a select' => ['SELECT /*+ INDEX(o idx) */ * FROM `object__published` o', ['object__published']];
        yield 'optimizer hint in an update' => ['UPDATE /*+ BKA(t) */ `object__published` SET likes = 1', ['object__published']];
        yield 'no_index hint in an update' => ['UPDATE /*+ NO_INDEX(t i) */ t SET a = 1', ['t']];
        yield 'optimizer hint in an insert' => ['INSERT /*+ SET_VAR(x=1) */ member (a) VALUES (1)', ['member']];
        yield 'optimizer hint in a replace' => ['REPLACE /*+ SET_VAR(x=1) */ member VALUES (1)', ['member']];
        yield 'optimizer hints in two statements' => ['SELECT /*+ x */ 1; UPDATE /*+ y */ t SET a=1', ['t']];
        yield 'plain comment stays a comment' => ['SELECT * FROM a /* UPDATE b SET c = 1 */', ['a']];
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
        $this->assertTrue(SqlTables::writes('SELECT 1; TRUNCATE likes'), 'a write after a read');
        $this->assertTrue(SqlTables::writes('SELECT 1; DROP TABLE likes'));
        $this->assertTrue(SqlTables::writes('INSERT member VALUES (1)'));
    }

    public function test_statements_that_change_no_data(): void
    {
        $this->assertFalse(SqlTables::changesNothing('/*!40000 UPDATE t SET a = 1 */'), 'an executable comment may write');
        $this->assertTrue(SqlTables::writes('/*!40000 UPDATE t SET a = 1 */'));
        $this->assertFalse(SqlTables::writes('SELECT /*+ INDEX(t i) */ * FROM t'));
        $this->assertTrue(SqlTables::changesNothing('SET NAMES utf8mb4'));
        $this->assertTrue(SqlTables::changesNothing('PRAGMA foreign_keys = ON'));
        $this->assertTrue(SqlTables::changesNothing('BEGIN; SET @a = 1'));
        $this->assertTrue(SqlTables::changesNothing('SELECT 1'));
        $this->assertFalse(SqlTables::changesNothing('SELECT 1 INTO @x'));
        $this->assertFalse(SqlTables::changesNothing('CREATE INDEX i ON t (a)'));
        $this->assertFalse(SqlTables::changesNothing('INSERT VALUES (1)'));
        $this->assertFalse(SqlTables::changesNothing('SET @a = 1; CALL x()'));
    }
}
