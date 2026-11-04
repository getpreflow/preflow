<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;

final class RedirectTest extends TestCase
{
    public function test_status_must_be_301_or_302(): void
    {
        $this->assertSame(302, $this->make(302)->status);

        $this->expectException(\InvalidArgumentException::class);
        $this->make(307);
    }

    public function test_id_is_32_hex_and_differs_by_kind_locale_and_path(): void
    {
        $id = Redirect::id('exact', 'de', '/a');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertSame($id, Redirect::id('exact', 'de', '/a'));
        $this->assertNotSame($id, Redirect::id('area', 'de', '/a'));
        $this->assertNotSame($id, Redirect::id('exact', 'en', '/a'));
        $this->assertNotSame($id, Redirect::id('exact', 'de', '/b'));
    }

    private function make(int $status): Redirect
    {
        return new Redirect('id', '/a', '', false, AreaMode::One, null, null, '/b', $status);
    }
}
