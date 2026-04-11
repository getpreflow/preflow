<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class MysqlDriver extends PdoDriver
{
    public function __construct(\PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES 'utf8mb4'");

        $dialect = new MysqlDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect));
    }
}
