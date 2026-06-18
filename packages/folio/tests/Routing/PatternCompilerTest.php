<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Routing\PatternCompiler;

final class PatternCompilerTest extends TestCase
{
    public function test_static_pattern(): void
    {
        $r = PatternCompiler::compile('/folio');
        $this->assertSame([], $r['paramNames']);
        $this->assertFalse($r['isCatchAll']);
        $this->assertSame(1, preg_match($r['regex'], '/folio'));
        $this->assertSame(0, preg_match($r['regex'], '/folio/x'));
    }

    public function test_named_params(): void
    {
        $r = PatternCompiler::compile('/folio/{type}/{id}/edit');
        $this->assertSame(['type', 'id'], $r['paramNames']);
        $this->assertFalse($r['isCatchAll']);
        $this->assertSame(1, preg_match($r['regex'], '/folio/page/abc/edit', $m));
        $this->assertSame('page', $m['type']);
        $this->assertSame('abc', $m['id']);
        $this->assertSame(0, preg_match($r['regex'], '/folio/page/abc'));
    }

    public function test_catch_all(): void
    {
        $r = PatternCompiler::compile('/{...path}');
        $this->assertSame(['path'], $r['paramNames']);
        $this->assertTrue($r['isCatchAll']);
        $this->assertSame(1, preg_match($r['regex'], '/a/b/c', $m));
        $this->assertSame('a/b/c', $m['path']);
    }
}
