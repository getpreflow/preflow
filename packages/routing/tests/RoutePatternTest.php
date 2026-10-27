<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Routing\RoutePattern;

/** A path pattern ("/members/confirm/{token}", "/{...path}") as the matcher's regex and parameter names. */
final class RoutePatternTest extends TestCase
{
    public function test_a_static_pattern_matches_only_itself(): void
    {
        $r = RoutePattern::compile('/members.x');
        $this->assertSame([], $r['paramNames']);
        $this->assertFalse($r['isCatchAll']);
        $this->assertSame(1, preg_match($r['regex'], '/members.x'));
        $this->assertSame(0, preg_match($r['regex'], '/membersXx'), 'literal characters are quoted');
        $this->assertSame(0, preg_match($r['regex'], '/members.x/y'));
    }

    public function test_named_parameters_take_one_segment(): void
    {
        $r = RoutePattern::compile('/konto/reset/{token}');
        $this->assertSame(['token'], $r['paramNames']);
        $this->assertSame(1, preg_match($r['regex'], '/konto/reset/abc', $m));
        $this->assertSame('abc', $m['token']);
        $this->assertSame(0, preg_match($r['regex'], '/konto/reset/a/b'));
    }

    public function test_a_catch_all_takes_the_rest(): void
    {
        $r = RoutePattern::compile('/files/{...path}');
        $this->assertSame(['path'], $r['paramNames']);
        $this->assertTrue($r['isCatchAll']);
        $this->assertSame(1, preg_match($r['regex'], '/files/a/b/c', $m));
        $this->assertSame('a/b/c', $m['path']);
    }
}
