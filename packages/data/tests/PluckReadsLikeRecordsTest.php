<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\FieldTransformer;
use Preflow\Data\Locales;
use Preflow\Data\Tests\Fixtures\ModelBridge\Gallery;
use Preflow\Data\Tests\Fixtures\ModelBridge\Ticket;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\PlainDriver;

/** pluck() gives the values a record reads, on a driver with SelectsColumns and on one without. */
final class PluckReadsLikeRecordsTest extends TestCase
{
    private \PDO $pdo;

    private string $dir;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Fixtures/ModelBridge/Gallery.php';
        require_once __DIR__ . '/Fixtures/ModelBridge/Ticket.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pluck_reads_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE gallery (uuid TEXT PRIMARY KEY, title TEXT, images TEXT, body TEXT, related TEXT, _version INTEGER)');
        $this->pdo->exec('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
        $this->pdo->exec("INSERT INTO gallery (uuid, title, images, related) VALUES ('a', 'x', '[\"p\",\"q\"]', NULL), ('b', '', 'null', '[]'), ('c', NULL, NULL, '[\"a\"]'), ('d', 'w', '[]', 'null')");
        $this->pdo->exec("INSERT INTO ticket (title) VALUES ('t1'), (NULL)");
    }

    protected function tearDown(): void
    {
        @rmdir($this->dir . '/models');
        @rmdir($this->dir);
    }

    private function manager(bool $plain): DataManager
    {
        $driver = new SqliteDriver($this->pdo);
        $types = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'), ['gallery' => Gallery::class, 'ticket' => Ticket::class]);

        return new DataManager(['default' => $plain ? new PlainDriver($driver) : $driver], 'default', $types, new ValidatorFactory(new RuleFactory()));
    }

    public function test_a_class_backed_type_reads_the_same_through_sql_and_through_records(): void
    {
        $sql = $this->manager(false);
        $plain = $this->manager(true);

        foreach ([[Gallery::class, ['uuid', 'title', 'images', 'related']], [Ticket::class, ['id', 'title']]] as [$class, $fields]) {
            foreach ($fields as $field) {
                $this->assertSame(
                    $plain->query($class)->orderBy($field)->pluck($field),
                    $sql->query($class)->orderBy($field)->pluck($field),
                    $class . '::' . $field,
                );
            }
        }
        $this->assertSame([['p', 'q'], null, null, []], $sql->query(Gallery::class)->orderBy('uuid')->pluck('images'));
    }

    public function test_a_type_transformer_runs_on_both_paths_and_a_subquery_compares_stored_values(): void
    {
        $upper = new class implements FieldTransformer {
            public function toStorage(mixed $value): mixed
            {
                return is_string($value) ? strtolower($value) : $value;
            }

            public function fromStorage(mixed $value): mixed
            {
                return is_string($value) ? strtoupper($value) : $value;
            }
        };
        $this->pdo->exec('CREATE TABLE `items` (`uuid` TEXT PRIMARY KEY, `code` TEXT)');
        $this->pdo->exec('CREATE TABLE `refs` (`uuid` TEXT PRIMARY KEY, `item_code` TEXT)');
        $this->pdo->exec("INSERT INTO `items` VALUES ('a', 'xy')");
        $this->pdo->exec("INSERT INTO `refs` VALUES ('r1', 'xy'), ('r2', 'zz')");
        $items = new TypeDefinition('items', 'items', 'default', ['uuid' => new TypeFieldDefinition('uuid'), 'code' => new TypeFieldDefinition('code')], transformers: ['code' => $upper]);
        $refs = new TypeDefinition('refs', 'refs', 'default', ['uuid' => new TypeFieldDefinition('uuid'), 'item_code' => new TypeFieldDefinition('item_code')]);

        $run = static function (\Preflow\Data\StorageDriver $d) use ($items, $refs): array {
            return [
                QueryBuilder::forType($d, $items)->pluck('code'),
                QueryBuilder::forType($d, $refs)->whereIn('item_code', QueryBuilder::forType($d, $items), 'code')->pluck('uuid'),
                QueryBuilder::forType($d, $refs)->whereNotIn('item_code', QueryBuilder::forType($d, $items), 'code')->pluck('uuid'),
            ];
        };
        $sqlite = new SqliteDriver($this->pdo);
        $this->assertSame($run(new PlainDriver($sqlite)), $run($sqlite));
        $this->assertSame([['XY'], ['r1'], ['r2']], $run($sqlite));
    }

    public function test_an_operator_other_than_in_and_a_bad_field_are_refused(): void
    {
        $driver = new SqliteDriver($this->pdo);
        $type = new TypeDefinition('samples', 'samples', 'default', ['uuid' => new TypeFieldDefinition('uuid')]);

        $this->expectException(\InvalidArgumentException::class);
        QueryBuilder::forType($driver, $type)->pluck('');
    }

    public function test_a_subselect_takes_only_in_and_not_in(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new \Preflow\Data\Query())->whereInSubselect('a', '=', new \Preflow\Data\Subselect('t', 'b', new \Preflow\Data\Query()));
    }
}
