<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class MysqlDriver extends PdoDriver
{
    /** Strict, portable session mode; ANSI_QUOTES is deliberately absent (we quote with backticks). */
    public const SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /**
     * Options to build the PDO with. FOUND_ROWS (UPDATE reports matched, not
     * changed, rows) can only be set at connect time, so it lives here and not
     * in the constructor; sql_mode and charset are set by the constructor.
     *
     * @return array<int, mixed>
     */
    public static function connectionOptions(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \Pdo\Mysql::ATTR_FOUND_ROWS => true,
        ];
    }

    public function __construct(\PDO $pdo, ?\Preflow\Core\Debug\DebugCollector $collector = null)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES 'utf8mb4'");
        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");

        $dialect = new MysqlDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect), $collector);
    }
}
