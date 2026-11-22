<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\IdKind;
use Preflow\Data\Migration\Grammar\ClassifiesIdColumns;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Migration\Grammar\SqliteGrammar;

/**
 * What kind of id a key column holds, by its type as the grammar's columnTypes() reports it
 * (ClassifiesIdColumns, since 0.82.0): SQLite by its affinity rules, MySQL by whether the server
 * compares a text value with the column as text.
 */
final class IdColumnClassificationTest extends TestCase
{
    /** @return iterable<string, array{string, ?IdKind}> SQLite's affinity: "int" anywhere a number, "char", "clob" or "text" text, else none */
    public static function sqliteTypes(): iterable
    {
        foreach (['integer', 'int', 'int8', 'int2', 'bigint', 'unsigned big int', 'tinyint unsigned', 'mediumint', 'int(11)'] as $type) {
            yield $type => [$type, IdKind::Int];
        }
        foreach (['text', 'varchar(64)', 'character(20)', 'nchar(55)', 'nvarchar(100)', 'native character(70)', 'varying character(255)', 'clob'] as $type) {
            yield $type => [$type, IdKind::String];
        }
        foreach (['numeric', 'decimal(10,5)', 'real', 'double', 'float', 'blob', 'boolean', 'date', ''] as $type) {
            yield "'{$type}'" => [$type, null];
        }
        yield 'point (contains "int", so integer affinity)' => ['point', IdKind::Int];
    }

    #[DataProvider('sqliteTypes')]
    public function test_sqlite_follows_its_affinity_rules(string $type, ?IdKind $expected): void
    {
        $grammar = new SqliteGrammar();

        $this->assertInstanceOf(ClassifiesIdColumns::class, $grammar);
        $this->assertSame($expected, $grammar->idKindOfColumn($type));
    }

    /** @return iterable<string, array{string, ?IdKind}> MySQL: integers and the other numbers compare a string as a number; text types as text */
    public static function mysqlTypes(): iterable
    {
        foreach (['int', 'int(11)', 'tinyint(1)', 'smallint', 'mediumint', 'bigint', 'bigint unsigned', 'bigint(20) unsigned', 'serial', 'integer',
            'decimal(10,0)', 'decimal(10,2) unsigned', 'numeric', 'double', 'double precision', 'float', 'real'] as $type) {
            yield $type => [$type, IdKind::Int];
        }
        foreach (['varchar(64)', 'varchar(191)', 'char(32)', 'text', 'tinytext', 'mediumtext', 'longtext'] as $type) {
            yield $type => [$type, IdKind::String];
        }
        foreach (['binary(16)', 'varbinary(64)', 'blob', 'json', 'date', 'datetime', 'enum(\'a\',\'b\')', 'bit(1)', ''] as $type) {
            yield "'{$type}'" => [$type, null];
        }
    }

    #[DataProvider('mysqlTypes')]
    public function test_mysql_compares_numbers_as_numbers(string $type, ?IdKind $expected): void
    {
        $grammar = new MysqlGrammar();

        $this->assertInstanceOf(ClassifiesIdColumns::class, $grammar);
        $this->assertSame($expected, $grammar->idKindOfColumn($type));
    }
}
