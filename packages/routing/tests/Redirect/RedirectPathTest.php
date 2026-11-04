<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Preflow\Routing\Redirect\RedirectPath;

final class RedirectPathTest extends TestCase
{
    public function test_normalize_collapses_slashes(): void
    {
        $this->assertSame('/old/evil.example/x', RedirectPath::normalize('/old//evil.example/x/'));
        $this->assertSame('/evil.example/x', RedirectPath::normalize('//evil.example/x'));
    }

    public function test_normalize_encodes_each_segment_once(): void
    {
        foreach (['/über-uns', '/%C3%BCber-uns', '/%c3%bcber-uns'] as $path) {
            $this->assertSame('/%C3%BCber-uns', RedirectPath::normalize($path));
        }
    }

    public function test_normalize_refuses_dot_segments_control_characters_and_long_paths(): void
    {
        $this->assertNull(RedirectPath::normalize('/a/../b'));
        $this->assertNull(RedirectPath::normalize('/a/%2E%2e/b'));
        $this->assertNull(RedirectPath::normalize("/a\x00"));
        $this->assertNull(RedirectPath::normalize('/a%00'));
        $this->assertNull(RedirectPath::normalize('/' . str_repeat('a', 301)));
        $this->assertNull(RedirectPath::normalize('no-slash'));
        $this->assertNotNull(RedirectPath::normalize('/' . str_repeat('a', 299)));
    }

    public function test_root_stays_root(): void
    {
        $this->assertSame('/', RedirectPath::normalize('/'));
        $this->assertSame('/', RedirectPath::normalize('///'));
    }

    public function test_under(): void
    {
        $this->assertSame('/basel', RedirectPath::under('/workshops-2024/basel', '/workshops-2024'));
        $this->assertSame('', RedirectPath::under('/workshops-2024', '/workshops-2024'));
        $this->assertNull(RedirectPath::under('/workshops-2024x', '/workshops-2024'));
    }
}
