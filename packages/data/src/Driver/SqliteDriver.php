<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class SqliteDriver extends PdoDriver
{
    public function __construct(\PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dialect = new SqliteDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect));
    }
}
