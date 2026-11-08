<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteCollection;
use Preflow\Routing\RouteEntry;

final class RouteCollectionInsertTest extends TestCase
{
    private static function entry(string $pattern): RouteEntry
    {
        return new RouteEntry($pattern, 'H@' . $pattern, 'GET', RouteMode::Action);
    }

    public function test_entries_go_in_front_of_the_anchor(): void
    {
        $collection = new RouteCollection();
        $collection->addMany([self::entry('/a'), self::entry('/{...path}'), self::entry('/z')]);

        // An equal entry (a new instance, e.g. rebuilt from a cache) is the anchor too.
        $collection->insertBefore(self::entry('/{...path}'), [self::entry('/b'), self::entry('/c')]);

        $this->assertSame(['/a', '/b', '/c', '/{...path}', '/z'], array_map(static fn (RouteEntry $e): string => $e->pattern, $collection->all()));
    }

    public function test_without_the_anchor_they_are_appended(): void
    {
        $collection = new RouteCollection();
        $collection->add(self::entry('/a'));

        $collection->insertBefore(self::entry('/{...path}'), [self::entry('/b')]);

        $this->assertSame(['/a', '/b'], array_map(static fn (RouteEntry $e): string => $e->pattern, $collection->all()));
    }
}
