<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\TextDuplicates;
use Preflow\Data\Schema\TextRuleConversion;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * On storage without unique indexes (JSON) a unique text field may hold case variants saved
 * before 0.78 ("Müller" and "MÜLLER"): `preflow migrate` lists them as on SQL, and refuses
 * (exit 1) until they differ; nothing is changed.
 */
final class TextDuplicatesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/textdup_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/data', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_json_case_variant_duplicates_are_reported(): void
    {
        $dm = $this->dm();
        $driver = $dm->driver('json');
        $driver->save('people', 'r2', ['uuid' => 'r2', 'title' => 'MÜLLER']);
        $driver->save('people', 'r1', ['uuid' => 'r1', 'title' => 'Müller']);
        $driver->save('people', 'r3', ['uuid' => 'r3', 'title' => 'Muller']);
        $driver->save('people', 'r4', ['uuid' => 'r4', 'title' => null]);
        $driver->save('people', 'r5', ['uuid' => 'r5', 'title' => null]);
        $before = $this->files();

        $this->assertSame([['value' => 'Müller', 'ids' => ['r1', 'r2']]], TextDuplicates::records($dm, self::people(), 'title'));

        $lines = $this->conversion($dm)->duplicates();
        $this->assertSame([
            'refused: people.title: 1 value is there more than once when case is ignored; saving one of these records is refused until they differ',
            '  "Müller" in r1, r2',
        ], $lines);
        $this->assertSame($before, $this->files(), 'nothing changed');

        $driver->save('people', 'r2', ['uuid' => 'r2', 'title' => 'Müller 2']);
        $this->assertSame([], $this->conversion($dm)->duplicates());
    }

    public function test_a_scope_and_an_exact_field(): void
    {
        $dm = $this->dm();
        $def = new TypeDefinition('code', 'code', 'json', [
            'title' => new TypeFieldDefinition('title', unique: ['purpose']),
            'purpose' => new TypeFieldDefinition('purpose'),
            'key' => new TypeFieldDefinition('key', unique: true, exact: true),
        ]);
        $driver = $dm->driver('json');
        $driver->save('code', 'a', ['uuid' => 'a', 'title' => 'Müller', 'purpose' => 'x', 'key' => 'Ab']);
        $driver->save('code', 'b', ['uuid' => 'b', 'title' => 'MÜLLER', 'purpose' => 'y', 'key' => 'aB']);
        $driver->save('code', 'c', ['uuid' => 'c', 'title' => 'müller', 'purpose' => 'X', 'key' => 'ab']);

        $this->assertSame([['value' => 'Müller', 'ids' => ['a', 'c']]], TextDuplicates::records($dm, $def, 'title'), 'within its scope, which compares by the rule too');
        $this->assertSame([], TextDuplicates::records($dm, $def, 'key'), 'an exact field keeps case');
    }

    public function test_a_translatable_field_is_checked_per_locale(): void
    {
        $dm = $this->dm();
        $def = new TypeDefinition('page', 'page', 'json', [
            'slug' => new TypeFieldDefinition('slug', unique: true, translatable: true),
        ], locales: new Locales(['de', 'en'], 'de'));
        $driver = $dm->driver('json');
        $driver->save('page', 'a', ['uuid' => 'a', 'slug' => ['de' => 'Über', 'en' => 'about']]);
        $driver->save('page', 'b', ['uuid' => 'b', 'slug' => ['de' => 'über-uns', 'en' => 'About']]);
        $driver->save('page', 'c', ['uuid' => 'c', 'slug' => ['de' => 'about', 'en' => 'other']]);

        $tables = new RecordTables();
        $tables->add('test', static fn (): array => [RecordTables::entry($def)]);
        $this->assertSame([
            'refused: page.slug (en): 1 value is there more than once when case is ignored; saving one of these records is refused until they differ',
            '  "about" in a, b',
        ], (new TextRuleConversion($dm, $tables))->duplicates());
    }

    private static function people(): TypeDefinition
    {
        return new TypeDefinition('people', 'people', 'json', [
            'title' => new TypeFieldDefinition('title', unique: true),
            'body' => new TypeFieldDefinition('body', 'text'),
        ]);
    }

    private function dm(): DataManager
    {
        return new DataManager(drivers: ['json' => new JsonFileDriver($this->dir . '/data')], defaultDriver: 'json');
    }

    private function conversion(DataManager $dm): TextRuleConversion
    {
        $tables = new RecordTables();
        $tables->add('test', static fn (): array => [RecordTables::entry(self::people())]);

        return new TextRuleConversion($dm, $tables);
    }

    /** @return array<string, string> every file under the data folder with its bytes */
    private function files(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir . '/data', \FilesystemIterator::SKIP_DOTS)) as $f) {
            $files[$f->getPathname()] = (string) file_get_contents($f->getPathname());
        }
        ksort($files);

        return $files;
    }
}
