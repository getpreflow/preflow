<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Migration\Grammar\SqliteGrammar;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** A grammar registered for a PDO driver name serves Schema, Migrator and RecordTableSync. */
final class GrammarsRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Grammars::forget('fakesql');
        Grammars::forget('sqlite');
    }

    public function test_built_in_grammars_by_driver_name(): void
    {
        $this->assertInstanceOf(SqliteGrammar::class, Grammars::for(new \PDO('sqlite::memory:')));
        $this->assertInstanceOf(MysqlGrammar::class, Grammars::named('mysql'));
    }

    public function test_an_unknown_driver_name_throws_and_says_how_to_register(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No schema grammar for PDO driver "fakesql". Register one with Grammars::register(\'fakesql\', $grammar).');

        Grammars::for(new FakeSqlPdo());
    }

    public function test_a_registered_grammar_is_used_by_record_table_sync(): void
    {
        $grammar = new SqliteGrammar();
        Grammars::register('fakesql', $grammar);
        $pdo = new FakeSqlPdo();

        $this->assertSame($grammar, Grammars::for($pdo));

        $dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default');
        $lines = (new RecordTableSync($dm))->sync(new TypeDefinition('note', 'note', 'default', ['title' => new TypeFieldDefinition('title')]));

        $this->assertSame(['created table note'], $lines);
        $this->assertSame(['uuid', 'title'], (new Schema($pdo))->columns('note'));
    }

    public function test_a_registered_grammar_wins_over_a_built_in_one_until_forgotten(): void
    {
        $grammar = new MysqlGrammar();
        Grammars::register('sqlite', $grammar);

        $this->assertSame($grammar, Grammars::named('sqlite'));
        Grammars::forget('sqlite');
        $this->assertInstanceOf(SqliteGrammar::class, Grammars::named('sqlite'));
    }
}

/** SQLite in memory, reporting itself as another PDO driver. */
final class FakeSqlPdo extends \PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === \PDO::ATTR_DRIVER_NAME ? 'fakesql' : parent::getAttribute($attribute);
    }
}
