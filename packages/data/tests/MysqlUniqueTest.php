<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/UniqueScenarios.php';
require_once __DIR__ . '/Driver/HookedPdo.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Tests\Driver\HookedPdo;
use Preflow\Data\UniqueViolation;
use Preflow\Testing\MysqlTestDatabase;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/** Unique fields on MySQL/MariaDB: the unique index refuses a duplicate and touches no row. */
#[Group('mysql')]
final class MysqlUniqueTest extends TestCase
{
    use UniqueScenarios;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
        $this->dm = new DataManager(['mysql' => new MysqlDriver($this->pdo)], 'mysql', null, new ValidatorFactory(new RuleFactory()));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }

    protected function storage(): string
    {
        return 'mysql';
    }

    public function testExactComparison(): void
    {
        $page = $this->page();
        foreach (['a' => 'cafe', 'b' => 'café', 'c' => 'Cafe'] as $id => $slug) {
            $this->assertSame([], $this->errorsOf($page, ['uuid' => $id, 'slug' => $slug]), $slug);
        }
        $this->assertSame([], $this->errorsOf($page, ['uuid' => 'd', 'slug' => 'CAFÉ'], validate: false));
        $this->assertSame(['slug' => ['This value is already taken.']], $this->errorsOf($page, ['uuid' => 'e', 'slug' => 'café']));
    }

    public function testDriverNamesTheViolatedIndex(): void
    {
        $page = $this->page();
        $this->save($page, ['uuid' => 'a', 'slug' => "it's for key 'PRIMARY'"]);

        try {
            $this->dm->driver('mysql')->save('page', 'b', ['slug' => "it's for key 'PRIMARY'"]);
            $this->fail('a duplicate must fail');
        } catch (UniqueViolation $e) {
            $this->assertSame('uniq_page_slug', $e->index);
            $this->assertSame('slug', $e->field('page', $page->uniqueFields()));
        }
        $this->assertNull($this->dm->findIn($page, 'b'));
    }

    public function testTranslatableCheckAndWriteHoldTheNamedLock(): void
    {
        $c = MysqlTestDatabase::dataConfig();
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']);
        $hooked = new HookedPdo($dsn, $c['username'], $c['password'], MysqlDriver::connectionOptions());
        $this->dm = new DataManager(['mysql' => new MysqlDriver($hooked)], 'mysql', null, new ValidatorFactory(new RuleFactory()));
        $doc = $this->doc();
        $holder = null;
        $hooked->beforePrepare = function (string $sql) use (&$holder): void {
            if (str_starts_with($sql, 'INSERT INTO `doc`')) {
                $holder = $this->pdo->query("SELECT IS_USED_LOCK('preflow_unique_doc')")->fetchColumn();
            }
        };

        $this->save($doc, ['uuid' => 'a', 'slug' => ['de' => 'spiel', 'en' => 'game']]);

        $own = $hooked->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->assertSame((string) $own, (string) $holder, 'the save holds the lock while it writes');
        $this->assertNull($this->pdo->query("SELECT IS_USED_LOCK('preflow_unique_doc')")->fetchColumn(), 'and releases it');
    }
}
