<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/UniqueScenarios.php';
require_once __DIR__ . '/Driver/HookedPdo.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Model;
use Preflow\Data\Tests\Driver\HookedPdo;
use Preflow\Data\UniqueViolation;
use Preflow\Data\Validation\Unique;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

#[Entity(table: 'member', storage: 'sqlite')]
final class UniqueMember extends Model
{
    #[Id]
    public int $id = 0;

    #[Field(unique: true)]
    public string $email = '';
}

/**
 * A PDO that reports only transactions opened through its API (beginTransaction()), not one
 * opened with exec('BEGIN …'), as PDO SQLite before PHP 8.5 does.
 */
final class ApiTransactionPdo extends \PDO
{
    private bool $open = false;

    public function beginTransaction(): bool
    {
        if ($this->open) {
            throw new \PDOException('There is already an active transaction');
        }
        $this->exec('BEGIN');

        return $this->open = true;
    }

    public function commit(): bool
    {
        $this->exec('COMMIT');
        $this->open = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->exec('ROLLBACK');
        $this->open = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }
}

/** Unique fields on SQLite: validation, the unique index as the guard, the lock for translatable fields. */
final class UniqueTest extends TestCase
{
    use UniqueScenarios;

    private string $dir;
    private HookedPdo $pdo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/unique_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->pdo = new HookedPdo('sqlite:' . $this->dir . '/db.sqlite');
        $this->dm = new DataManager(['sqlite' => new SqliteDriver($this->pdo)], 'sqlite', null, new ValidatorFactory(new RuleFactory()));
    }

    protected function tearDown(): void
    {
        unset($this->pdo, $this->dm);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    protected function storage(): string
    {
        return 'sqlite';
    }

    public function testDriverNamesTheViolatedColumns(): void
    {
        $code = $this->code();
        $this->save($code, ['uuid' => 'a', 'code' => 'ABC', 'purpose' => 'p1']);

        try {
            $this->dm->driver('sqlite')->save('code', 'b', ['code' => 'ABC', 'purpose' => 'p1']);
            $this->fail('a duplicate must fail');
        } catch (UniqueViolation $e) {
            $this->assertSame(['code', 'purpose'], $e->columns);
            $this->assertSame('code', $e->field('code', $code->uniqueFields()));
            $this->assertSame('23000', $e->errorInfo[0]);
        }
    }

    public function testUniqueRuleForAppForms(): void
    {
        $code = $this->code();
        $this->save($code, ['uuid' => 'a', 'code' => 'ABC', 'purpose' => 'p1']);
        $validate = fn (Unique $rule, array $data): array => (new ValidatorFactory(new RuleFactory()))
            ->make(['code' => [$rule]], $data)->validate()->errors();

        $this->assertSame(['code' => ['This value is already taken.']], $validate(new Unique($this->dm, $code, 'code', ['purpose']), ['code' => 'ABC', 'purpose' => 'p1']));
        $this->assertSame([], $validate(new Unique($this->dm, $code, 'code', ['purpose']), ['code' => 'ABC', 'purpose' => 'p2']));
        $this->assertSame([], $validate(new Unique($this->dm, $code, 'code', ['purpose' => 'p2']), ['code' => 'ABC']), 'a fixed scope value');
        $this->assertSame([], $validate(new Unique($this->dm, $code, 'code', ['purpose'], exceptId: 'a'), ['code' => 'ABC', 'purpose' => 'p1']));
        $this->assertSame([], $validate(new Unique($this->dm, $code, 'code', ['purpose']), ['code' => 'abc', 'purpose' => 'p1']), 'compared exactly');
        $this->assertSame([], $validate(new Unique($this->dm, $code, 'code'), ['code' => '']));
    }

    public function testTranslatableCheckAndWriteHoldTheWriteLock(): void
    {
        $doc = $this->doc();
        $other = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->setAttribute(\PDO::ATTR_TIMEOUT, 0);
        $locked = null;
        $this->pdo->beforePrepare = function (string $sql) use ($other, &$locked): void {
            if (!str_starts_with($sql, 'INSERT INTO "doc"')) {
                return;
            }
            try {
                $other->exec('BEGIN IMMEDIATE');
                $other->exec('ROLLBACK');
                $locked = false;
            } catch (\PDOException) {
                $locked = true;
            }
        };

        $this->save($doc, ['uuid' => 'a', 'slug' => ['de' => 'spiel', 'en' => 'game']]);

        $this->assertTrue($locked, 'no other connection can write between the check and the write');
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testWriteLockIsReleasedWithoutPdoReportingTheTransaction(): void
    {
        $pdo = new ApiTransactionPdo('sqlite:' . $this->dir . '/db.sqlite');
        $this->dm = new DataManager(['sqlite' => new SqliteDriver($pdo)], 'sqlite', null, new ValidatorFactory(new RuleFactory()));
        $doc = $this->doc();
        $pdo->exec('ALTER TABLE doc ADD COLUMN _version TEXT');
        $other = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->setAttribute(\PDO::ATTR_TIMEOUT, 0);
        $writable = static function () use ($other): bool {
            try {
                $other->exec('BEGIN IMMEDIATE');
                $other->exec('ROLLBACK');

                return true;
            } catch (\PDOException) {
                return false;
            }
        };

        $this->save($doc, ['uuid' => 'a', 'slug' => ['de' => 'spiel', 'en' => 'game']]);
        $this->assertTrue($writable(), 'released after a save');

        $taken = ['slug.en' => ['This value is already taken.']];
        $this->assertSame($taken, $this->errorsOf($doc, ['uuid' => 'b', 'slug' => ['de' => 'zwei', 'en' => 'game']], validate: false));
        $this->assertTrue($writable(), 'released after a failing check');

        $record = DynamicRecord::fromArray($doc, ['uuid' => 'c', 'slug' => ['de' => 'drei', 'en' => 'three']]);
        $this->assertTrue($this->dm->saveTypeIfVersion($record, 0, validate: false), 'a versioned save inside the lock opens no second transaction');
        $this->assertFalse($this->dm->saveTypeIfVersion(DynamicRecord::fromArray($doc, ['uuid' => 'c', 'slug' => ['de' => 'drei']]), 5, validate: false));
        $this->assertTrue($writable(), 'released after versioned saves');
        $this->assertSame('three', \Preflow\Data\LocaleValue::pick($this->dm->findIn($doc, 'c')?->get('slug'), $doc->locales, 'en'));
    }

    public function testModelDuplicateIsAFieldError(): void
    {
        $this->pdo->exec('CREATE TABLE member (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
        $this->pdo->exec('CREATE UNIQUE INDEX uniq_member_email ON member (email)');
        $first = new UniqueMember();
        $first->email = 'a@x.test';
        $this->dm->save($first);

        foreach ([true, false] as $validate) {
            $second = new UniqueMember();
            $second->email = 'a@x.test';
            try {
                $this->dm->insert($second, $validate);
                $this->fail('a duplicate email must fail');
            } catch (ValidationException $e) {
                $this->assertSame(['email' => ['This value is already taken.']], $e->errors());
            }
        }

        $first->email = 'a@x.test';
        $this->dm->update($first);
        $this->assertCount(1, $this->dm->query(UniqueMember::class)->get()->items());
    }

    public function testUnmappedViolationStaysADatabaseError(): void
    {
        $page = $this->page();
        $this->pdo->exec('CREATE UNIQUE INDEX other_title ON page (title)');
        $this->save($page, ['uuid' => 'a', 'title' => 'T']);

        $this->expectException(UniqueViolation::class);
        $this->dm->saveType(DynamicRecord::fromArray($page, ['uuid' => 'b', 'title' => 'T']));
    }
}
