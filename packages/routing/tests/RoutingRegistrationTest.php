<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Core\Routing\PageExists as CorePageExists;
use Preflow\Routing\Redirect\PageExists;
use Preflow\Routing\RoutingRegistration;

/** A lookup bound under routing's Redirect\PageExists answers under core's PageExists too. */
final class RoutingRegistrationTest extends TestCase
{
    /** @param list<string> $paths */
    private static function lookup(array $paths): PageExists
    {
        return new class ($paths) implements PageExists {
            /** @param list<string> $paths */
            public function __construct(private readonly array $paths) {}

            public function exists(string $locale, string $path): bool
            {
                return in_array($path, $this->paths, true);
            }
        };
    }

    public function test_routings_name_alone_is_bound_under_cores(): void
    {
        $container = new Container();
        $lookup = self::lookup(['/blog']);
        $container->instance(PageExists::class, $lookup);

        RoutingRegistration::alias($container);

        $this->assertTrue($container->bound(CorePageExists::class));
        $this->assertSame($lookup, $container->get(CorePageExists::class));
    }

    public function test_both_names_answer_under_cores(): void
    {
        $container = new Container();
        $container->instance(CorePageExists::class, self::lookup(['/about']));
        $container->instance(PageExists::class, self::lookup(['/blog']));

        RoutingRegistration::alias($container);

        $pages = $container->get(CorePageExists::class);
        $this->assertTrue($pages->exists('en', '/about'));
        $this->assertTrue($pages->exists('en', '/blog'));
        $this->assertFalse($pages->exists('en', '/nope'));
    }

    public function test_one_lookup_under_both_names_stays_as_it_is(): void
    {
        $container = new Container();
        $lookup = self::lookup(['/about']);
        $container->instance(CorePageExists::class, $lookup);
        $container->instance(PageExists::class, $lookup);

        RoutingRegistration::alias($container);

        $this->assertSame($lookup, $container->get(CorePageExists::class));
    }

    public function test_nothing_bound_stays_unbound(): void
    {
        $container = new Container();

        RoutingRegistration::alias($container);

        $this->assertFalse($container->bound(CorePageExists::class), 'core must still see that nothing can tell a page');
    }
}
