<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\NonPublicPaths;

final class NonPublicPathsTest extends TestCase
{
    public function test_component_endpoint_is_in_from_the_start(): void
    {
        $paths = new NonPublicPaths();

        $this->assertTrue($paths->matches('/--component/click'));
        $this->assertSame(['/--component'], $paths->all());
    }

    public function test_matches_are_segment_bounded(): void
    {
        $paths = new NonPublicPaths();
        $paths->add('/folio/');

        $this->assertTrue($paths->matches('/folio'));
        $this->assertTrue($paths->matches('/folio/x'));
        $this->assertFalse($paths->matches('/folios'));
        $this->assertFalse($paths->matches('/about/folio'));
    }

    public function test_root_prefix_is_ignored(): void
    {
        $paths = new NonPublicPaths();
        $paths->add('');
        $paths->add('/');

        $this->assertFalse($paths->matches('/anything'));
        $this->assertSame(['/--component'], $paths->all());
    }
}
