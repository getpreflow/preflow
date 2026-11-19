<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/IntIdInsertScenarios.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Testing\MysqlTestDatabase;

/** insert() with a given int id on MySQL/MariaDB (IntIdInsertTest has SQLite and JSON). */
#[Group('mysql')]
final class MysqlIntIdInsertTest extends TestCase
{
    use IntIdInsertScenarios;

    private \PDO $pdo;

    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
        $this->pdo->exec('CREATE TABLE tallies (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(255))');
        $this->pdo->exec('CREATE TABLE typed_tallies (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(255))');
        $this->dir = sys_get_temp_dir() . '/preflow_mysql_int_id_' . bin2hex(random_bytes(4));
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

    /** @return iterable<string, array{string, bool}> */
    public static function kindsAndModels(): iterable
    {
        yield 'mysql, plain model' => ['mysql', false];
        yield 'mysql, class-backed type' => ['mysql', true];
    }

    protected function generatesIds(string $kind): bool
    {
        return true;
    }

    protected function manager(string $kind, bool $typed = false): DataManager
    {
        $types = $typed ? new TypeRegistry($this->dir, new Locales(['en'], 'en'), ['typed_tallies' => TypedTallyRow::class]) : null;

        return new DataManager(['default' => new MysqlDriver($this->pdo)], 'default', $types);
    }
}
