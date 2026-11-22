<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/IdKindScenarios.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Testing\MysqlTestDatabase;

/** A type's id kind on MySQL/MariaDB (IdKindTest has SQLite and JSON files). */
#[Group('mysql')]
final class MysqlIdKindTest extends TestCase
{
    use IdKindScenarios;

    private \PDO $pdo;

    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
        $this->dir = sys_get_temp_dir() . '/preflow_mysql_id_kind_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
        if (isset($this->dir) && is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        yield 'mysql' => ['mysql'];
    }

    protected function sql(string $kind): bool
    {
        return true;
    }

    protected function generatesIds(string $kind): bool
    {
        return true;
    }

    protected function manager(string $kind): DataManager
    {
        $types = new TypeRegistry($this->dir, new Locales(['en'], 'en'), [
            'kind_string_rows' => StringIdKindRow::class,
            'kind_int_rows' => IntIdKindRow::class,
        ]);

        return new DataManager(['default' => new MysqlDriver($this->pdo)], 'default', $types);
    }

    protected function table(string $kind, string $table, bool $intKey, ?string $key = null): void
    {
        $key ??= $intKey ? 'id' : 'uuid';
        $this->pdo->exec("CREATE TABLE {$table} ({$key} " . ($intKey ? 'INT AUTO_INCREMENT' : 'VARCHAR(64)') . ' PRIMARY KEY, label VARCHAR(255))');
    }
}
