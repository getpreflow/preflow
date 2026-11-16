<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * A typed query on a field the type does not have fails the same on every driver (0.78): SQL
 * would name the missing column, JSON would quietly match nothing.
 */
final class QueryFieldGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/query_guard_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_a_field_the_type_lacks_is_refused_on_every_driver(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $sqlite = new SqliteDriver($pdo);
        $sqlite->execute('CREATE TABLE `note` (`uuid` TEXT PRIMARY KEY, `title` TEXT, `_version` INTEGER)');
        $dm = new DataManager(['json' => new JsonFileDriver($this->dir . '/json'), 'sqlite' => $sqlite], 'json');

        foreach (['json', 'sqlite'] as $storage) {
            $def = new TypeDefinition('note', 'note', $storage, ['title' => new TypeFieldDefinition('title')]);
            $this->assertSame([], $dm->queryIn($def)->where('title', 'x')->where('uuid', 'a')->where('_version', 1)->orderBy('title')->get()->items(), $storage);
            foreach ([
                'where' => static fn () => $dm->queryIn($def)->where('titel', 'x'),
                'orWhere' => static fn () => $dm->queryIn($def)->where('title', 'x')->orWhere('titel', 'y'),
                'whereAny' => static fn () => $dm->queryIn($def)->whereAny([['field' => 'titel', 'operator' => '=', 'value' => 'x']]),
                'orderBy' => static fn () => $dm->queryIn($def)->orderBy('titel'),
            ] as $what => $query) {
                try {
                    $query();
                    $this->fail("{$storage}: {$what} on a missing field must be refused");
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame('Type "note" has no field "titel" to query by.', $e->getMessage(), "{$storage} {$what}");
                }
            }
        }
    }
}
