<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Query;

/** One total order for the JSON driver: orderBy and range conditions agree, whatever the ids look like. */
final class JsonOrderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/json_order_' . bin2hex(random_bytes(4));
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

    /** @return list<string> */
    private static function ids(\Preflow\Data\ResultSet $rows): array
    {
        return array_map(static fn (array $r): string => (string) $r['id'], $rows->items());
    }

    public function test_json_order_is_total_for_mixed_ids(): void
    {
        $driver = new JsonFileDriver($this->dir);
        foreach (['9', '10', '1a', '2', 'b'] as $id) {
            $driver->save('thing', $id, ['id' => $id], 'id');
        }

        $order = self::ids($driver->findMany('thing', (new Query())->orderBy('id')));
        $this->assertSame(['2', '9', '10', '1a', 'b'], $order, 'numbers by value first, then text by bytes');
        $this->assertSame(array_reverse($order), self::ids($driver->findMany('thing', (new Query())->orderBy('id', \Preflow\Data\SortDirection::Desc))));

        foreach ($order as $position => $x) {
            $this->assertSame(
                array_slice($order, $position + 1),
                self::ids($driver->findMany('thing', (new Query())->where('id', '>', $x)->orderBy('id'))),
                "after {$x}",
            );
            $this->assertSame(
                array_slice($order, 0, $position),
                self::ids($driver->findMany('thing', (new Query())->where('id', '<', $x)->orderBy('id'))),
                "before {$x}",
            );
        }
    }

    public function test_equal_numbers_with_other_spelling_still_have_one_place(): void
    {
        $driver = new JsonFileDriver($this->dir);
        foreach (['1.0', '007', '1', '7'] as $id) {
            $driver->save('thing', $id, ['id' => $id], 'id');
        }

        $order = self::ids($driver->findMany('thing', (new Query())->orderBy('id')));
        $this->assertSame(['1', '1.0', '007', '7'], $order, 'same value: the spelling decides, by bytes');
        foreach ($order as $position => $x) {
            $this->assertSame(
                array_slice($order, $position + 1),
                self::ids($driver->findMany('thing', (new Query())->where('id', '>', $x)->orderBy('id'))),
                "after {$x}",
            );
        }
    }
}
