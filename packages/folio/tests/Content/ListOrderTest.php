<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\ListOrder;

/** "list.sort" on SQL storage, where Folio's table sync keeps every column as TEXT. */
final class ListOrderTest extends TestCase
{
    private string $dir;
    private SqliteDriver $driver;
    private TypeRegistry $types;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE level (uuid TEXT PRIMARY KEY, name TEXT, num TEXT)');
        foreach ([['a', 'Nine', '9'], ['b', 'Ten', '10'], ['c', 'Two', '2']] as [$id, $name, $num]) {
            $pdo->prepare('INSERT INTO level VALUES (?, ?, ?)')->execute([$id, $name, $num]);
        }
        $this->driver = new SqliteDriver($pdo);

        $this->dir = sys_get_temp_dir() . '/folio_list_order_' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/level.json', json_encode([
            'key' => 'level',
            'table' => 'level',
            'storage' => 'sqlite',
            'fields' => ['name' => ['type' => 'string'], 'num' => ['type' => 'number']],
        ]));
        $this->types = new TypeRegistry($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /** @return list<string> */
    private function names(string $sort): array
    {
        $def = $this->types->get('level');
        $query = ListOrder::apply(QueryBuilder::forType($this->driver, $def), $def, ['sort' => $sort]);

        return array_map(static fn ($r) => (string) $r->get('name'), $query->get()->items());
    }

    public function test_sort_by_number_field_compares_numbers(): void
    {
        $this->assertSame(['Two', 'Nine', 'Ten'], $this->names('num'));
        $this->assertSame(['Ten', 'Nine', 'Two'], $this->names('-num'));
    }

    public function test_sort_by_text_field_stays_textual(): void
    {
        $this->assertSame(['Nine', 'Ten', 'Two'], $this->names('name'));
    }
}
