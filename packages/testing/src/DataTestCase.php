<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Schema;

abstract class DataTestCase extends TestCase
{
    private ?\PDO $pdo = null;
    private ?string $jsonDir = null;
    private ?SqliteDriver $sqliteDriver = null;
    private ?JsonFileDriver $jsonDriver = null;
    private ?DataManager $dataManager = null;
    private ?MysqlDriver $mysqlDriver = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->jsonDir = sys_get_temp_dir() . '/preflow_test_data_' . uniqid();
        mkdir($this->jsonDir, 0755, true);

        $this->sqliteDriver = new SqliteDriver($this->pdo);
        $this->jsonDriver = new JsonFileDriver($this->jsonDir);
        $this->dataManager = null; // reset
    }

    protected function tearDown(): void
    {
        if ($this->mysqlDriver !== null) {
            MysqlTestDatabase::dropAllTables($this->mysqlDriver->pdo());
            $this->mysqlDriver = null;
        }
        if ($this->jsonDir !== null && is_dir($this->jsonDir)) {
            $this->deleteDir($this->jsonDir);
        }
        parent::tearDown();
    }

    protected function getSqliteDriver(): SqliteDriver
    {
        return $this->sqliteDriver;
    }

    protected function getJsonDriver(): JsonFileDriver
    {
        return $this->jsonDriver;
    }

    /**
     * Runs the rest of the test on MySQL: 'mysql' and 'default' then name a
     * MysqlDriver on the emptied test database (the test skips without MYSQL_* env).
     * Its tables are dropped again in tearDown.
     */
    protected function useMysql(): MysqlDriver
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);
        $this->mysqlDriver = new MysqlDriver($pdo);
        $this->dataManager = null;

        return $this->mysqlDriver;
    }

    protected function usesMysql(): bool
    {
        return $this->mysqlDriver !== null;
    }

    protected function dataManager(): DataManager
    {
        if ($this->dataManager === null) {
            $drivers = [
                'sqlite' => $this->sqliteDriver,
                'json' => $this->jsonDriver,
                'default' => $this->sqliteDriver,
            ];
            if ($this->mysqlDriver !== null) {
                $drivers['mysql'] = $drivers['default'] = $this->mysqlDriver;
            }
            $this->dataManager = new DataManager($drivers);
        }
        return $this->dataManager;
    }

    protected function createTable(string $name, callable $callback): void
    {
        $schema = new Schema($this->pdo);
        $schema->create($name, $callback);
    }

    protected function getPdo(): \PDO
    {
        return $this->pdo;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
