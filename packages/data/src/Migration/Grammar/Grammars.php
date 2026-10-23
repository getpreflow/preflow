<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

final class Grammars
{
    public static function for(\PDO $pdo): SchemaGrammar
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'sqlite' => new SqliteGrammar(),
            'mysql' => new MysqlGrammar(),
            default => throw new \LogicException("No schema grammar for PDO driver \"{$driver}\"."),
        };
    }
}
