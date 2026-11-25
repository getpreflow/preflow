<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Add;
use Preflow\Data\Condition;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\ConditionCompiler;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * DataManager::updateWhen() / deleteWhen() beyond the storage driver contract (which runs the
 * cases on every driver): one statement per write on SQL, the SQL a condition compiles to, and
 * the refusals of a condition or a write that cannot be made.
 */
final class ConditionalWritesTest extends TestCase
{
    private CountingPdo $pdo;
    private DataManager $dm;
    private TypeDefinition $def;

    protected function setUp(): void
    {
        $this->pdo = new CountingPdo('sqlite::memory:');
        $driver = new SqliteDriver($this->pdo);
        $this->pdo->exec('CREATE TABLE "counters" ("id" TEXT PRIMARY KEY, "label" TEXT, "uses" INTEGER, "cap" INTEGER)');
        $this->pdo->exec("INSERT INTO \"counters\" VALUES ('a', 'A', 1, 2)");
        $this->dm = new DataManager(['default' => $driver]);
        $this->def = new TypeDefinition(
            key: 'counters',
            table: 'counters',
            storage: 'default',
            fields: [
                'label' => new TypeFieldDefinition('label', 'string'),
                'uses' => new TypeFieldDefinition('uses', 'number'),
                'cap' => new TypeFieldDefinition('cap', 'number'),
            ],
            idField: 'id',
            changeDates: false,
        );
        $this->pdo->statements = [];
    }

    public function test_an_update_on_sql_is_one_statement(): void
    {
        $when = Condition::whereField('uses', '<', 'cap', numbers: true);

        $this->assertTrue($this->dm->updateWhen($this->def, 'a', $when, ['uses' => new Add(1)]));
        $this->assertCount(1, $this->pdo->statements, implode("\n", $this->pdo->statements));
        $this->assertStringStartsWith('UPDATE `counters` SET `uses` = COALESCE(', $this->pdo->statements[0]);
        $this->assertStringContainsString('WHERE `id` = ? AND (', $this->pdo->statements[0]);

        $this->pdo->statements = [];
        $this->assertFalse($this->dm->updateWhen($this->def, 'a', $when, ['uses' => new Add(1)]), 'used up: 2 < 2 does not hold');
        $this->assertCount(2, $this->pdo->statements, 'an Add always changes its row: no affected row is no match; one read tells a refused overflow from it');
        $this->assertStringStartsWith('SELECT', $this->pdo->statements[1]);
        $this->assertSame(2, (int) $this->dm->findIn($this->def, 'a')?->get('uses'));

        $this->pdo->statements = [];
        $this->assertFalse($this->dm->updateWhen($this->def, 'a', Condition::where('label', '=', 'B'), ['label' => 'C']));
        $this->assertCount(2, $this->pdo->statements, 'a write that may leave its row as it was is confirmed (MySQL without FOUND_ROWS)');
        $this->assertStringStartsWith('SELECT 1 FROM `counters`', $this->pdo->statements[1]);
        $this->assertStringContainsString('`label`', substr($this->pdo->statements[1], (int) strrpos($this->pdo->statements[1], 'AND')), 'the read also asks for the values written');
    }

    public function test_a_storage_that_only_runs_sql_writes_in_one_statement_too(): void
    {
        $dm = new DataManager(['default' => new SqlOnly(new SqliteDriver($this->pdo))]);
        $this->pdo->statements = [];

        $this->assertTrue($dm->updateWhen($this->def, 'a', Condition::where('uses', '<', 2), ['uses' => new Add(1)]));
        $this->assertCount(1, $this->pdo->statements, implode("\n", $this->pdo->statements));
        $this->assertStringStartsWith('UPDATE `counters`', $this->pdo->statements[0]);
        $this->assertFalse($dm->updateWhen($this->def, 'a', Condition::where('uses', '<', 2), ['uses' => new Add(1)]));

        $this->pdo->statements = [];
        $this->assertSame('A', $dm->deleteWhen($this->def, 'a', Condition::where('uses', '=', 2))?->get('label'));
        $this->assertSame(['DELETE'], array_values(array_map(static fn (string $sql): string => strtok($sql, ' '), array_filter($this->pdo->statements, static fn (string $sql): bool => !str_starts_with($sql, 'SELECT')))));
    }

    public function test_a_missing_column_names_the_fix(): void
    {
        foreach ([
            fn () => $this->dm->updateWhen($this->def, 'a', Condition::all(), ['gone' => 1]),
            fn () => $this->dm->deleteWhen($this->def, 'a', Condition::where('gone', '=', 1)),
        ] as $write) {
            try {
                $write();
                $this->fail('refused');
            } catch (\RuntimeException $e) {
                $this->assertSame('Table counters has no column gone; run `preflow migrate`.', $e->getMessage());
            }
        }
    }

    public function test_a_delete_on_sql_is_one_statement_after_the_read_of_the_record(): void
    {
        $deleted = $this->dm->deleteWhen($this->def, 'a', Condition::where('label', '=', 'A'));

        $this->assertSame('A', $deleted?->get('label'));
        $writes = array_values(array_filter($this->pdo->statements, static fn (string $sql): bool => !str_starts_with($sql, 'SELECT')));
        $this->assertCount(1, $writes);
        $this->assertStringStartsWith('DELETE FROM `counters` WHERE `id` = ? AND (', $writes[0]);
    }

    public function test_the_compiled_condition_reads_numbers_and_texts_through_the_dialect_and_binds_every_value(): void
    {
        $bindings = [];
        $sql = (new ConditionCompiler(new SqliteDialect()))->where(Condition::all(
            Condition::isEmpty('note'),
            Condition::any(Condition::where('uses', '>=', 3), Condition::where('label', 'IN', ['a', 'b'])),
            Condition::where('label', '!=', ''),
        ), $bindings);

        $this->assertSame(
            '((`note` IS NULL OR LENGTH((`note` COLLATE BINARY)) = 0)) AND ((' . (new SqliteDialect())->wholeNumber('`uses`') . ' >= ?)'
                . ' OR (((`label` COLLATE BINARY) = ? AND LENGTH((`label` COLLATE BINARY)) = LENGTH(?)) OR ((`label` COLLATE BINARY) = ? AND LENGTH((`label` COLLATE BINARY)) = LENGTH(?))))'
                . ' AND (((`label` COLLATE BINARY) <> ? OR LENGTH((`label` COLLATE BINARY)) <> LENGTH(?)))',
            $sql,
        );
        $this->assertSame([3, 'a', 'a', 'b', 'b', '', ''], $bindings);
    }

    public function test_an_update_needs_fields_other_than_the_id(): void
    {
        foreach ([[], ['id' => 'b']] as $set) {
            try {
                $this->dm->updateWhen($this->def, 'a', Condition::all(), $set);
                $this->fail('refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('give at least one other field', $e->getMessage());
            }
        }
    }

    public function test_a_condition_built_by_a_function_must_be_a_condition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('return one from the function');

        $this->dm->updateWhen($this->def, 'a', static fn (): bool => true, ['label' => 'B']);
    }

    public function test_a_condition_built_by_a_function_is_built_when_the_write_checks_it(): void
    {
        $built = 0;
        $this->dm->updateWhen($this->def, 'a', static function () use (&$built): Condition {
            $built++;

            return Condition::all();
        }, ['label' => 'B']);

        $this->assertSame(1, $built);
    }

    public function test_a_condition_refuses_what_it_cannot_compare_and_says_what_to_give(): void
    {
        $refused = [
            'operator' => [static fn () => Condition::where('uses', 'LIKE', 'a'), 'use one of'],
            'float' => [static fn () => Condition::where('uses', '>', 1.5), 'give a whole number or a text'],
            'bool' => [static fn () => Condition::where('uses', '=', true), 'give a whole number or a text'],
            'null' => [static fn () => Condition::where('uses', '=', null), "Condition::isEmpty('uses')"],
            'list without IN' => [static fn () => Condition::where('uses', '=', [1]), "a list only with 'IN'"],
            'IN without a list' => [static fn () => Condition::where('uses', 'IN', 1), 'give a list'],
            'mixed list' => [static fn () => Condition::where('uses', 'IN', [1, 'a']), 'all of one kind'],
            'IN between fields' => [static fn () => Condition::whereField('uses', 'IN', 'cap'), 'use =, !=, <, >, <= or >='],
            'no field' => [static fn () => Condition::isEmpty(''), 'give the name of a stored field'],
        ];
        foreach ($refused as $what => [$make, $hint]) {
            try {
                $make();
                $this->fail("{$what} was not refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($hint, $e->getMessage(), $what);
            }
        }
    }

    public function test_stored_values_read_as_whole_numbers_and_texts(): void
    {
        $this->assertNull(Condition::wholeNumber(null));
        $this->assertNull(Condition::wholeNumber(''));
        $this->assertSame(3, Condition::wholeNumber('3.0'));
        $this->assertSame(-2, Condition::wholeNumber('-2.5'));
        $this->assertSame(0, Condition::wholeNumber('abc'));
        $this->assertSame(0, Condition::wholeNumber(' 3'));
        $this->assertSame(0, Condition::wholeNumber('3 '));
        $this->assertSame(0, Condition::wholeNumber('1e3'));
        $this->assertSame(0, Condition::wholeNumber("3\n"));
        foreach (['.5', '0x1A', '+3', '-', '1.2.3', '-0.5'] as $text) {
            $this->assertSame(0, Condition::wholeNumber($text), $text);
        }
        $this->assertSame(-3, Condition::wholeNumber('-3.7'));
        $this->assertSame(3, Condition::wholeNumber('3.'));
        $this->assertSame(3, Condition::wholeNumber('3.99999999999999999999'));
        $this->assertSame(-3, Condition::wholeNumber('-3.99999999999999999999'));
        $this->assertSame(9007199254740993, Condition::wholeNumber('9007199254740993.5'));
        $this->assertSame(0, Condition::wholeNumber("3\0"));
        $this->assertSame(0, Condition::wholeNumber('  '));
        // Beyond 64 bits a float clamps (as text, SQLite and MySQL do), it does not wrap.
        $this->assertSame(PHP_INT_MAX, Condition::wholeNumber(1e20));
        $this->assertSame(PHP_INT_MIN, Condition::wholeNumber(-1e20));
        $this->assertSame(PHP_INT_MAX, Condition::wholeNumber(9.2233720368547758E18));
        $this->assertSame(PHP_INT_MAX, Condition::wholeNumber(INF));
        $this->assertSame(PHP_INT_MIN, Condition::wholeNumber(-INF));
        $this->assertSame(0, Condition::wholeNumber(NAN));
        $this->assertSame(-3, Condition::wholeNumber(-3.9));
        $this->assertSame(1000000000000000, Condition::wholeNumber(1e15));
        $this->assertSame(9007199254740993, Condition::wholeNumber('9007199254740993'));
        $this->assertSame(1, Condition::wholeNumber(true));
        $this->assertNull(Condition::text(null));
        $this->assertSame('', Condition::text(''));
        $this->assertSame('5', Condition::text(5));
        $this->assertSame('["a","ü/b"]', Condition::text(['a', 'ü/b']));
        $this->assertSame(1, (new Add(1))->applyTo(null));
        $this->assertSame(PHP_INT_MAX, (new Add(1))->applyTo(PHP_INT_MAX - 1));
        $this->assertSame(PHP_INT_MIN, (new Add(-1))->applyTo((string) (PHP_INT_MIN + 1)));
        foreach ([[PHP_INT_MAX, 1, 'largest'], [(string) PHP_INT_MIN, -1, 'smallest'], [1e20, 1, 'largest']] as [$stored, $amount, $word]) {
            try {
                (new Add($amount))->applyTo($stored, 'uses');
                $this->fail('adding past the 64-bit range was not refused');
            } catch (\OverflowException $e) {
                $this->assertStringContainsString('[uses]', $e->getMessage());
                $this->assertStringContainsString($word . ' whole number the storage keeps', $e->getMessage());
                $this->assertStringEndsWith('nothing was written. Add a smaller amount or reset the field.', $e->getMessage());
            }
        }
        $this->assertSame(2, (new Add(-1))->applyTo('3'));
    }
}

/** A connection that keeps every statement it prepares, so a test can count them. */
final class CountingPdo extends \Pdo\Sqlite
{
    /** @var list<string> */
    public array $statements = [];

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }
}

/** A storage that runs SQL and nothing else: no ConditionalWrites, not a PdoDriver. */
final class SqlOnly implements \Preflow\Data\RunsSql
{
    public function __construct(private readonly SqliteDriver $inner) {}

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        return $this->inner->findOne($type, $id, $idField);
    }

    public function findMany(string $type, \Preflow\Data\Query $query): \Preflow\Data\ResultSet
    {
        return $this->inner->findMany($type, $query);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $this->inner->save($type, $id, $data, $idField);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $this->inner->delete($type, $id, $idField);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return $this->inner->exists($type, $id, $idField);
    }

    public function lastInsertId(): string|int
    {
        return $this->inner->lastInsertId();
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        return $this->inner->rawQuery($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->inner->execute($sql, $bindings);
    }

    public function dialect(): \Preflow\Data\Driver\Dialect
    {
        return $this->inner->dialect();
    }
}
