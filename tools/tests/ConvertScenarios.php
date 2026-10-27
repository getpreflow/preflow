<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert\Tests;

require_once dirname(__DIR__) . '/Convert/autoload.php';

use Preflow\Data\DataManager;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Tools\Convert\Backup;
use Preflow\Tools\Convert\Checker;
use Preflow\Tools\Convert\Conversion;
use Preflow\Tools\Convert\Failure;
use Preflow\Tools\Convert\Tables;

/**
 * The conversion on any SQL server: legacy record tables (all text, no indexes, as sync made
 * them before typed columns) are checked, backed up, converted and re-checked.
 */
trait ConvertScenarios
{
    private DataManager $dm;
    private string $backups;

    abstract private function backup(): Backup;

    abstract private function storage(): string;

    public function testCheckListsEveryBadValue(): void
    {
        $this->legacy(self::event());
        $this->insert('event', [
            ['uuid' => 'a', 'seats' => '12 Spieler'],
            ['uuid' => 'b', 'open' => 'ja'],
            ['uuid' => 'c', 'starts' => '27.10.2026'],
            ['uuid' => 'd', 'slug' => 'x'],
            ['uuid' => 'e', 'slug' => 'x'],
        ]);
        $before = $this->snapshot('event');

        $failures = (new Checker($this->tables()))->check();

        $this->assertSame([
            ['event', 'a', 'seats', '12 Spieler'],
            ['event', 'b', 'open', 'ja'],
            ['event', 'c', 'starts', '27.10.2026'],
            ['event', 'e', 'slug', 'x'],
        ], array_map(static fn (Failure $f): array => [$f->table, $f->id, $f->field, $f->value], $failures));

        $lines = [];
        $this->assertSame(1, $this->conversion()->run(false, function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $this->assertContains('event e slug: "x" (duplicate of record d)', $lines);
        $this->assertSame($before, $this->snapshot('event'));
        $this->assertSame([], $this->backupFiles());
    }

    public function testConvertTypesAndKeepsValues(): void
    {
        $this->legacy(self::event());
        $this->insert('event', [
            ['uuid' => 'a', 'seats' => '12', 'open' => '1', 'starts' => '2026-10-27', 'slug' => 'a', 'name' => ''],
            ['uuid' => 'b', 'seats' => '12.5', 'open' => '', 'starts' => '2026-10-27T10:00:00+01:00', 'slug' => ''],
            ['uuid' => 'c', 'seats' => '', 'open' => 'true', 'starts' => '', 'slug' => ''],
            ['uuid' => 'd', 'seats' => '9', 'open' => 'FALSE', 'starts' => '2026-10-27T10:00'],
        ]);

        $this->assertSame(0, $this->conversion()->run(false, static fn (string $line) => null));

        $rows = $this->snapshot('event');
        $this->assertEquals(12, $rows['a']['seats']);
        $this->assertEquals(12.5, $rows['b']['seats']);
        $this->assertNull($rows['c']['seats']);
        $this->assertEquals(1, $rows['a']['open']);
        $this->assertNull($rows['b']['open']);
        $this->assertEquals(1, $rows['c']['open']);
        $this->assertEquals(0, $rows['d']['open']);
        $this->assertSame('2026-10-27', $rows['a']['starts']);
        $this->assertSame('2026-10-27T10:00:00+01:00', $rows['b']['starts']);
        $this->assertNull($rows['c']['starts']);
        $this->assertNull($rows['b']['slug'], "an empty unique value is NULL, not ''");
        $this->assertSame('', $rows['a']['name'], "empty text stays ''");
        $this->assertSame(['c', 'd', 'a', 'b'], $this->pdo()->query('SELECT uuid FROM event ORDER BY seats, uuid')->fetchAll(\PDO::FETCH_COLUMN));

        // As a fresh typed sync makes it: sync finds nothing to change or report.
        $this->assertSame([], (new RecordTableSync($this->dm))->sync(self::event()));
        $schema = new Schema($this->pdo());
        $this->assertContains('uniq_event_slug', $schema->indexes('event'));
        $this->assertContains('idx_event_city', $schema->indexes('event'));
        $this->assertCount(1, $this->backupFiles());
    }

    public function testSecondRunNothingToConvert(): void
    {
        $this->legacy(self::event());
        $this->insert('event', [['uuid' => 'a', 'seats' => '3']]);
        $this->assertSame(0, $this->conversion()->run(false, static fn (string $line) => null));

        $lines = [];
        $this->assertSame(0, $this->conversion()->run(false, function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        $this->assertSame(['Nothing to convert: every record table is typed.'], $lines);
        $this->assertCount(1, $this->backupFiles());
    }

    public function testRevisionsUntouched(): void
    {
        $revisions = new TypeDefinition('event', 'event__revisions', $this->storage(), self::event()->fields);
        $this->legacy($revisions);
        $this->insert('event__revisions', [['uuid' => 'r1', 'seats' => '12 Spieler', 'open' => 'ja']]);
        $types = (new Schema($this->pdo()))->columnTypes('event__revisions');
        $entries = [RecordTables::entry($revisions, typed: false, unique: false)];

        $this->assertSame([], (new Checker($this->tables($entries)))->check());
        $this->assertSame(0, $this->conversion($entries)->run(false, static fn (string $line) => null));

        $this->assertSame($types, (new Schema($this->pdo()))->columnTypes('event__revisions'));
        $this->assertSame('12 Spieler', $this->snapshot('event__revisions')['r1']['seats']);
        $this->assertSame([], $this->backupFiles());
    }

    public function testInterruptedRunContinues(): void
    {
        $this->legacy(self::event());
        $this->legacy(self::venue());
        $this->insert('event', [['uuid' => 'a', 'seats' => '12']]);
        $this->insert('venue', [['uuid' => 'v', 'capacity' => '80', 'slug' => 'hall']]);
        $entries = [RecordTables::entry(self::event()), RecordTables::entry(self::venue())];

        // Killed after the first table: the rest is left as it was.
        $this->assertSame(1, $this->conversion($entries)->run(false, static function (string $line): void {
            if (str_starts_with($line, 'converted ')) {
                throw new \RuntimeException('killed');
            }
        }));
        $schema = new Schema($this->pdo());
        $this->assertNotSame($this->expectedType('number'), $schema->columnTypes('venue')['capacity']);
        $this->assertSame($this->expectedType('number'), $schema->columnTypes('event')['seats']);

        $lines = [];
        $this->assertSame(0, $this->conversion($entries)->run(false, function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        $converted = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'converted ')));
        $this->assertSame(['converted venue (1 row)'], $converted);
        $this->assertSame($this->expectedType('number'), $schema->columnTypes('venue')['capacity']);
        $this->assertEquals(12, $this->snapshot('event')['a']['seats']);
        $this->assertCount(2, $this->backupFiles(), 'the first backup stays');
    }

    /** A field added since the table was made has no column yet: migrate adds it and its index, typed. */
    public function testFieldWithoutColumnIsLeftToMigrate(): void
    {
        $old = self::event();
        $fields = $old->fields;
        unset($fields['city']);
        $this->legacy(new TypeDefinition('event', 'event', $old->storage, $fields));
        $this->insert('event', [['uuid' => 'a', 'seats' => '3']]);

        $this->assertSame(0, $this->conversion()->run(false, static fn (string $line) => null));

        $schema = new Schema($this->pdo());
        $this->assertNotContains('idx_event_city', $schema->indexes('event'));
        $this->assertSame(['added column event.city', 'added index idx_event_city'], (new RecordTableSync($this->dm))->sync(self::event()));
    }

    private static function event(?string $storage = null): TypeDefinition
    {
        return new TypeDefinition('event', 'event', $storage ?? self::STORAGE, [
            'seats' => new TypeFieldDefinition('seats', 'number'),
            'open' => new TypeFieldDefinition('open', 'boolean'),
            'starts' => new TypeFieldDefinition('starts', 'date'),
            'slug' => new TypeFieldDefinition('slug', unique: true),
            'city' => new TypeFieldDefinition('city', index: true),
            'name' => new TypeFieldDefinition('name'),
        ]);
    }

    private static function venue(): TypeDefinition
    {
        return new TypeDefinition('venue', 'venue', self::STORAGE, [
            'capacity' => new TypeFieldDefinition('capacity', 'number'),
            'slug' => new TypeFieldDefinition('slug', unique: true),
        ]);
    }

    /** A table as sync made it before typed columns: every column text, no indexes. */
    private function legacy(TypeDefinition $def): void
    {
        (new RecordTableSync($this->dm))->sync($def, typed: false);
    }

    /** @param list<array<string, string>> $rows */
    private function insert(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            $this->dm->driver($this->storage())->save($table, $row['uuid'], $row);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function snapshot(string $table): array
    {
        $rows = [];
        foreach ($this->pdo()->query("SELECT * FROM {$table} ORDER BY uuid")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[$row['uuid']] = $row;
        }

        return $rows;
    }

    /** @param list<array<string, mixed>>|null $entries */
    private function tables(?array $entries = null): Tables
    {
        return Tables::from($this->dm, $entries ?? [RecordTables::entry(self::event())], $this->storage());
    }

    /** @param list<array<string, mixed>>|null $entries */
    private function conversion(?array $entries = null): Conversion
    {
        return new Conversion($this->tables($entries), $this->backup());
    }

    /** @return list<string> */
    private function backupFiles(): array
    {
        return is_dir($this->backups) ? array_values(array_diff(scandir($this->backups), ['.', '..'])) : [];
    }

    private function pdo(): \PDO
    {
        return $this->dm->driver($this->storage())->pdo();
    }

    private function expectedType(string $kind): string
    {
        return strtolower((string) preg_replace('/\s+COLLATE\s+\S+/i', '', \Preflow\Data\Migration\Grammar\Grammars::for($this->pdo())
            ->recordColumnType(\Preflow\Data\Schema\ColumnKind::from($kind))));
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
