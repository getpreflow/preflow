<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/AggregateScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** Aggregate queries on the JSON driver: grouped and computed in PHP, as SQL does. */
final class AggregateJsonTest extends TestCase
{
    use AggregateScenarios;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/json_aggregate_' . bin2hex(random_bytes(4));
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->dir, $this->dir . '/.cache')], 'json');
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

    /** Records written outside DataManager may hold numbers as text: they count as numbers, '' as none. */
    public function test_numeric_text_in_a_number_field_counts_as_a_number(): void
    {
        $score = new TypeDefinition('score', 'score', 'json', [
            'team' => new TypeFieldDefinition('team'),
            'points' => new TypeFieldDefinition('points', 'number'),
        ]);
        $driver = $this->dm->driver('json');
        foreach (['a' => '3', 'b' => 4, 'c' => '', 'd' => null, 'e' => '10', 'f' => '4.0'] as $id => $points) {
            $driver->save('score', $id, ['uuid' => $id, 'team' => 't', 'points' => $points]);
        }

        $rows = $this->dm->queryIn($score)->aggregate()
            ->sum('points', 'total')
            ->avg('points', 'average')
            ->max('points', 'most')
            ->countDistinct('points', 'distinct')
            ->get();
        $this->assertSame([['total' => 21, 'average' => 5.25, 'most' => 10, 'distinct' => 3]], $rows);

        $groups = $this->dm->queryIn($score)->where('uuid', '!=', 'c')->aggregate()->groupBy('points')->count()->get();
        $this->assertSame([
            ['points' => null, 'count' => 1],
            ['points' => 3, 'count' => 1],
            ['points' => 4, 'count' => 2],
            ['points' => 10, 'count' => 1],
        ], $groups, "4 and '4.0' are one group, ordered as numbers");
    }
}
