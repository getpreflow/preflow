<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\Locales;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

final class ColumnKindTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ck_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/item.json', json_encode([
            'key' => 'item', 'table' => 'items', 'storage' => 'sqlite',
            'fields' => [
                'qty' => ['type' => 'number'],
                'active' => ['type' => 'boolean'],
                'due' => ['type' => 'date'],
                'note' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'unique' => true],
                'code' => ['type' => 'string', 'index' => true, 'length' => 10],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/item.json');
        @rmdir($this->dir);
    }

    public function test_column_kind_of(): void
    {
        $kinds = new FieldKinds();
        $this->assertSame(ColumnKind::Number, $kinds->columnKindOf(new TypeFieldDefinition('n', 'number')));
        $this->assertSame(ColumnKind::Text, $kinds->columnKindOf(new TypeFieldDefinition('s', 'string')));
        $this->assertSame(ColumnKind::Short, $kinds->columnKindOf(new TypeFieldDefinition('s', 'string', unique: true)));
        $this->assertSame(ColumnKind::Short, $kinds->columnKindOf(new TypeFieldDefinition('s', 'string', index: true)));
        $this->assertSame(ColumnKind::Text, $kinds->columnKindOf(new TypeFieldDefinition('s', 'string', translatable: true, unique: true)));
        $this->assertSame(ColumnKind::Text, $kinds->columnKindOf(new TypeFieldDefinition('r', 'relation', config: ['multiple' => true], unique: true)));
        $this->assertSame(ColumnKind::Short, $kinds->columnKindOf(new TypeFieldDefinition('r', 'relation', unique: true)));
        $this->assertSame(ColumnKind::Text, $kinds->columnKindOf(new TypeFieldDefinition('x', 'mystery', unique: true)));
    }

    public function test_registered_kind_and_closure(): void
    {
        $kinds = new FieldKinds();
        $kinds->column('money', ColumnKind::Number);
        $kinds->column('pick', static fn (array $c): ColumnKind => ($c['wide'] ?? false) ? ColumnKind::Text : ColumnKind::Short);
        $this->assertSame(ColumnKind::Number, $kinds->columnKindOf(new TypeFieldDefinition('m', 'money')));
        $this->assertSame(ColumnKind::Short, $kinds->columnKindOf(new TypeFieldDefinition('p', 'pick', unique: true)));
        $this->assertSame(ColumnKind::Text, $kinds->columnKindOf(new TypeFieldDefinition('p', 'pick', config: ['wide' => true], unique: true)));
    }

    public function test_empty_becomes_null_for_typed_kinds(): void
    {
        [$dm, $pdo, $registry] = $this->dm();
        $pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, qty, active, due, note, slug, code)');
        $dm->saveType(DynamicRecord::fromArray($registry->get('item'), [
            'uuid' => 'a', 'qty' => '', 'active' => '', 'due' => '', 'note' => '', 'slug' => 's', 'code' => 'c',
        ]));
        $row = $pdo->query("SELECT * FROM items WHERE uuid = 'a'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['qty']);
        $this->assertNull($row['active']);
        $this->assertNull($row['due']);
        $this->assertSame('', $row['note']);
    }

    public function test_unique_short_field_gets_length_rule(): void
    {
        [$dm, , $registry] = $this->dm();
        $type = $registry->get('item');
        $dm->validate(DynamicRecord::fromArray($type, ['uuid' => 'a', 'slug' => str_repeat('x', 191)]));
        try {
            $dm->validate(DynamicRecord::fromArray($type, ['uuid' => 'a', 'slug' => str_repeat('x', 192)]));
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('191', (string) $e->errors()['slug'][0]);
        }
        $this->expectException(ValidationException::class);
        $dm->validate(DynamicRecord::fromArray($type, ['uuid' => 'a', 'code' => str_repeat('x', 11)]));
    }

    /** @return array{0: DataManager, 1: \PDO, 2: TypeRegistry} */
    private function dm(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $driver = new SqliteDriver($pdo);
        $registry = new TypeRegistry($this->dir, Locales::single('en'));
        $dm = new DataManager(
            drivers: ['sqlite' => $driver, 'default' => $driver],
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );

        return [$dm, $pdo, $registry];
    }
}
