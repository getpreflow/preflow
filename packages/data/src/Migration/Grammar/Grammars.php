<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

/**
 * The SchemaGrammar for a connection, by its PDO driver name (PDO::ATTR_DRIVER_NAME). SQLite and
 * MySQL come built in; a driver for another SQL database registers its grammar, for example in
 * its constructor or a service provider's register():
 *
 *     Grammars::register('pgsql', new PgsqlGrammar());
 *
 * Schema, Migrator and RecordTableSync (and so `preflow migrate`) then work on that database.
 * A registered grammar wins over a built-in one of the same name.
 */
final class Grammars
{
    /** @var array<string, SchemaGrammar> */
    private static array $registered = [];

    public static function for(\PDO $pdo): SchemaGrammar
    {
        return self::named((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /** The grammar for a PDO driver name ('sqlite', 'mysql', or one registered). */
    public static function named(string $driver): SchemaGrammar
    {
        return self::$registered[$driver] ?? match ($driver) {
            'sqlite' => new SqliteGrammar(),
            'mysql' => new MysqlGrammar(),
            default => throw new \LogicException(
                "No schema grammar for PDO driver \"{$driver}\". Register one with Grammars::register('{$driver}', \$grammar)."
            ),
        };
    }

    /** Use $grammar for connections whose PDO driver name is $driver. */
    public static function register(string $driver, SchemaGrammar $grammar): void
    {
        self::$registered[$driver] = $grammar;
    }

    /** Remove a registered grammar (a built-in one of that name applies again). */
    public static function forget(string $driver): void
    {
        unset(self::$registered[$driver]);
    }
}
