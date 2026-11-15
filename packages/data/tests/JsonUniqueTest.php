<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/UniqueScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/** Unique fields on the JSON driver: checked under the type's lock, validated or not. */
final class JsonUniqueTest extends TestCase
{
    use UniqueScenarios;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/json_unique_' . bin2hex(random_bytes(4));
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->dir, $this->dir . '/.cache')], 'json', null, new ValidatorFactory(new RuleFactory()));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    protected function storage(): string
    {
        return 'json';
    }

    public function testDuplicateFromBeforeDoesNotBlockOtherChanges(): void
    {
        $page = $this->page();
        $this->dm->driver('json')->save('page', 'a', ['uuid' => 'a', 'slug' => 'x', 'title' => 'A']);
        $this->dm->driver('json')->save('page', 'b', ['uuid' => 'b', 'slug' => 'x', 'title' => 'B']);

        $this->assertSame([], $this->errorsOf($page, ['uuid' => 'b', 'slug' => 'x', 'title' => 'B2'], validate: false));
        $this->assertSame('B2', $this->stored($page, 'b', 'title'));
        $this->assertSame(['slug'], array_keys($this->errorsOf($page, ['uuid' => 'b', 'slug' => 'x', 'title' => 'B3'])), 'validation still tells');
    }

    public function testVersionedSaveIsCheckedUnderTheSameLock(): void
    {
        $page = $this->page();
        $this->save($page, ['uuid' => 'a', 'slug' => 'x']);

        $this->expectException(\Preflow\Validation\ValidationException::class);
        $this->dm->saveTypeIfVersion(\Preflow\Data\DynamicRecord::fromArray($page, ['uuid' => 'b', 'slug' => 'x']), 0, validate: false);
    }

    private function person(bool $exact = false): \Preflow\Data\TypeDefinition
    {
        return new \Preflow\Data\TypeDefinition('person', 'person', 'json', [
            'name' => new \Preflow\Data\TypeFieldDefinition('name', unique: true, exact: $exact),
            'note' => new \Preflow\Data\TypeFieldDefinition('note'),
        ]);
    }

    public function test_a_case_variant_is_a_duplicate_on_a_text_field(): void
    {
        $person = $this->person();
        $this->save($person, ['uuid' => 'a', 'name' => 'MÜLLER']);

        $this->assertSame(['name'], array_keys($this->errorsOf($person, ['uuid' => 'b', 'name' => 'Müller'])));
        $this->assertSame(['name'], array_keys($this->errorsOf($person, ['uuid' => 'b', 'name' => 'Müller'], validate: false)), 'the check under the lock too');
        $this->assertNull($this->dm->findIn($person, 'b'));
        $this->assertSame([], $this->errorsOf($person, ['uuid' => 'c', 'name' => 'Muller']), 'an accent variant is another name');
        $this->assertSame([], $this->errorsOf($person, ['uuid' => 'a', 'name' => 'MÜLLER', 'note' => 'unchanged name']));
    }

    public function test_an_unrelated_save_of_a_record_with_a_case_variant_twin_is_refused(): void
    {
        $person = $this->person();
        $this->dm->driver('json')->save('person', 'a', ['uuid' => 'a', 'name' => 'Müller']);
        $this->dm->driver('json')->save('person', 'b', ['uuid' => 'b', 'name' => 'MÜLLER']);

        $this->assertSame(['name'], array_keys($this->errorsOf($person, ['uuid' => 'b', 'name' => 'MÜLLER', 'note' => 'x'])), 'as on SQL, where the index refuses it');
    }

    public function test_a_case_variant_of_an_exact_field_is_not_a_duplicate(): void
    {
        $code = new \Preflow\Data\TypeDefinition('key', 'key', 'json', [
            'code' => new \Preflow\Data\TypeFieldDefinition('code', unique: true, exact: true),
        ]);
        $this->save($code, ['uuid' => 'a', 'code' => 'Ab']);

        $this->assertSame([], $this->errorsOf($code, ['uuid' => 'b', 'code' => 'aB']));
        $this->assertSame(['code'], array_keys($this->errorsOf($code, ['uuid' => 'c', 'code' => 'Ab'])));
    }

    public function test_a_scope_field_compares_by_its_own_kind(): void
    {
        $code = new \Preflow\Data\TypeDefinition('scoped', 'scoped', 'json', [
            'code' => new \Preflow\Data\TypeFieldDefinition('code', unique: ['purpose', 'key'], exact: true),
            'purpose' => new \Preflow\Data\TypeFieldDefinition('purpose'),
            'key' => new \Preflow\Data\TypeFieldDefinition('key', exact: true),
        ]);
        $this->save($code, ['uuid' => 'a', 'code' => 'Ab', 'purpose' => 'Invite', 'key' => 'K']);

        $this->assertSame(['code'], array_keys($this->errorsOf($code, ['uuid' => 'b', 'code' => 'Ab', 'purpose' => 'INVITE', 'key' => 'K'])), 'a text scope ignores case');
        $this->assertSame([], $this->errorsOf($code, ['uuid' => 'c', 'code' => 'Ab', 'purpose' => 'Invite', 'key' => 'k']), 'an exact scope does not');
    }
}
