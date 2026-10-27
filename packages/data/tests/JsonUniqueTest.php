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
}
