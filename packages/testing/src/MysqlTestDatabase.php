<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\SkippedWithMessageException;
use Preflow\Data\Driver\MysqlDriver;

/**
 * One shared MySQL connection for tests, configured from MYSQL_* env vars.
 * Without them, tests skip instead of fail, so the suite stays green anywhere.
 */
final class MysqlTestDatabase
{
    private const SKIP_MESSAGE = 'MySQL not configured (set MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASS)';

    private static ?\PDO $pdo = null;

    public static function configured(): bool
    {
        return self::env('MYSQL_HOST') !== '' && self::env('MYSQL_DATABASE') !== '';
    }

    /** Memoised per process; forked children must call reconnect(). */
    public static function pdo(): \PDO
    {
        return self::$pdo ??= self::connect();
    }

    public static function reconnect(): \PDO
    {
        self::$pdo = null;

        return self::pdo();
    }

    /** Drops every table in the current database, ignoring foreign keys. */
    public static function dropAllTables(\PDO $pdo): void
    {
        $tables = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(\PDO::FETCH_COLUMN);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * For tests that write a config/data.php drivers.mysql entry.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public static function dataConfig(): array
    {
        if (!self::configured()) {
            throw new SkippedWithMessageException(self::SKIP_MESSAGE);
        }

        return [
            'host' => self::env('MYSQL_HOST'),
            'port' => self::env('MYSQL_PORT') ?: '3306',
            'database' => self::env('MYSQL_DATABASE'),
            'username' => self::env('MYSQL_USER'),
            'password' => self::env('MYSQL_PASS'),
        ];
    }

    private static function connect(): \PDO
    {
        $c = self::dataConfig();

        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            MysqlDriver::connectionOptions(),
        );
        $pdo->exec("SET SESSION sql_mode = '" . MysqlDriver::SQL_MODE . "'");

        return $pdo;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return $value === false ? '' : $value;
    }
}
