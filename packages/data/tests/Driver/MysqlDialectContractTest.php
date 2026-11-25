<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use Preflow\Data\Driver\Dialect;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Testing\Contract\DialectContract;
use Preflow\Testing\MysqlTestDatabase;

/** The dialect contract on MySQL/MariaDB in strict mode (`composer test:mysql`; skipped without MYSQL_* settings). */
#[Group('mysql')]
final class MysqlDialectContractTest extends DialectContract
{
    protected function createPdo(): \PDO
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);

        return $pdo;
    }

    protected function createDialect(): Dialect
    {
        return new MysqlDialect();
    }

    /** Beyond a 64-bit integer MySQL clamps to its bounds (as SQLite's CAST and PHP's (int)) instead of failing the UPDATE. */
    public function test_whole_number_clamps_beyond_64_bits_inside_an_update(): void
    {
        $this->pdo->exec('CREATE TABLE `huge` (`id` INT PRIMARY KEY, `num` DOUBLE, `txt` TEXT, `rn` BIGINT, `rt` BIGINT)');
        $this->pdo->exec("INSERT INTO `huge` (`id`, `num`, `txt`) VALUES (1, 1e20, '99999999999999999999'), (2, -1e20, '-99999999999999999999.5'),"
            . " (3, 9223372036854775807, '0009223372036854775807'), (4, 0, '-9223372036854775808')");
        $whole = $this->dialect->wholeNumber(...);
        $this->pdo->exec("UPDATE `huge` SET `rn` = {$whole('`num`')}, `rt` = {$whole('`txt`')} WHERE {$whole('`txt`')} IS NOT NULL");

        $rows = $this->pdo->query('SELECT `rn`, `rt` FROM `huge` ORDER BY `id`')->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([[PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MIN, PHP_INT_MIN], [PHP_INT_MAX, PHP_INT_MAX], [0, PHP_INT_MIN]], array_map(
            static fn (array $r): array => [(int) $r[0], (int) $r[1]],
            $rows,
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }
}
