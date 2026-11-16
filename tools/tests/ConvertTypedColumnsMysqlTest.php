<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert\Tests;

require_once __DIR__ . '/ConvertScenarios.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Schema\RecordTables;
use Preflow\Testing\MysqlTestDatabase;
use Preflow\Tools\Convert\Backup;
use Preflow\Tools\Convert\Checker;
use Preflow\Tools\Convert\Failure;

/** tools/convert-typed-columns.php on MySQL/MariaDB: one ALTER TABLE per table, a mysqldump backup. */
#[Group('mysql')]
final class ConvertTypedColumnsMysqlTest extends TestCase
{
    use ConvertScenarios;

    private const string STORAGE = 'mysql';

    protected function setUp(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);
        $this->backups = sys_get_temp_dir() . '/convert_' . bin2hex(random_bytes(4));
        $this->dm = new DataManager(['mysql' => new MysqlDriver($pdo)], 'mysql');
    }

    protected function tearDown(): void
    {
        if (isset($this->dm)) {
            MysqlTestDatabase::dropAllTables($this->pdo());
            self::removeDir($this->backups);
        }
    }

    private function backup(): Backup
    {
        return new Backup($this->pdo(), $this->backups, MysqlTestDatabase::dataConfig());
    }

    private function storage(): string
    {
        return self::STORAGE;
    }

    /** A unique text field compares by the text rule, as its column will: case is ignored, accents count. */
    public function testUniqueComparesByTheTextRule(): void
    {
        $this->legacy(self::event());
        $this->insert('event', [
            ['uuid' => 'a', 'slug' => 'cafe'],
            ['uuid' => 'b', 'slug' => 'Cafe'],
            ['uuid' => 'c', 'slug' => 'café'],
        ]);

        $failures = (new Checker($this->tables()))->check();

        $this->assertSame([['event', 'b', 'slug', 'Cafe']], array_map(static fn (Failure $f): array => [$f->table, $f->id, $f->field, $f->value], $failures));
        $this->pdo()->exec("DELETE FROM event WHERE uuid = 'b'");
        $this->assertSame(0, $this->conversion()->run(false, static fn (string $line) => null));
        $this->assertSame(['cafe', 'café'], array_column($this->snapshot('event'), 'slug'));
    }

    /**
     * The rule's collation does not pad: 'x' and 'x ' are two values of a text field. An exact
     * field's utf8mb4_bin pads: there they are one value in the unique index, so a duplicate.
     */
    public function testTrailingSpaceIsADuplicateOnlyOfAnExactField(): void
    {
        $def = new \Preflow\Data\TypeDefinition('event', 'event', self::STORAGE, [
            'slug' => new \Preflow\Data\TypeFieldDefinition('slug', unique: true),
            'key' => new \Preflow\Data\TypeFieldDefinition('key', unique: true, exact: true),
        ]);
        $entries = [RecordTables::entry($def)];
        $this->legacy($def);
        $this->insert('event', [['uuid' => 'a', 'slug' => 'x', 'key' => 'x'], ['uuid' => 'b', 'slug' => 'x ', 'key' => 'x ']]);

        $failures = (new Checker($this->tables($entries)))->check();

        $this->assertSame([['event', 'b', 'key', 'x ']], array_map(static fn (Failure $f): array => [$f->table, $f->id, $f->field, $f->value], $failures));
    }

    public function testShortValueOverItsLengthFails(): void
    {
        $this->legacy(self::event());
        $this->insert('event', [['uuid' => 'a', 'city' => str_repeat('ä', 192)]]);

        $failures = (new Checker($this->tables()))->check();

        $this->assertCount(1, $failures);
        $this->assertSame('longer than 191 characters', $failures[0]->reason);
    }

    public function testBackupIsADumpWithoutCredentialsLeftBehind(): void
    {
        $this->legacy(self::event());
        $this->insert('event', [['uuid' => 'a', 'seats' => '12 Spieler']]);
        $before = glob(sys_get_temp_dir() . '/convert-my-*') ?: [];

        $path = $this->backup()->create();

        $this->assertStringStartsWith($this->backups . '/', $path);
        $dump = (string) file_get_contents($path);
        $this->assertStringContainsString('CREATE TABLE `event`', $dump);
        $this->assertStringContainsString('12 Spieler', $dump);
        $this->assertStringNotContainsString(MysqlTestDatabase::dataConfig()['password'], $dump);
        $this->assertSame($before, glob(sys_get_temp_dir() . '/convert-my-*') ?: [], 'the option file is gone');
    }

    public function testScopedUniqueAllowsTheSameValueInAnotherScope(): void
    {
        $def = new \Preflow\Data\TypeDefinition('code', 'code', 'mysql', [
            'code' => new \Preflow\Data\TypeFieldDefinition('code', unique: ['purpose']),
            'purpose' => new \Preflow\Data\TypeFieldDefinition('purpose'),
        ]);
        $this->legacy($def);
        $this->insert('code', [
            ['uuid' => 'a', 'code' => 'X1', 'purpose' => 'invite'],
            ['uuid' => 'b', 'code' => 'X1', 'purpose' => 'reset'],
            ['uuid' => 'c', 'code' => 'X1', 'purpose' => 'invite'],
        ]);
        $entries = [RecordTables::entry($def)];

        $failures = (new Checker($this->tables($entries)))->check();

        $this->assertSame([['code', 'c', 'code', 'X1']], array_map(static fn (Failure $f): array => [$f->table, $f->id, $f->field, $f->value], $failures));
        $this->pdo()->exec("DELETE FROM code WHERE uuid = 'c'");
        $this->assertSame(0, $this->conversion($entries)->run(false, static fn (string $line) => null));
        $this->assertSame([], (new \Preflow\Data\Schema\RecordTableSync($this->dm))->sync($def));
    }
}
