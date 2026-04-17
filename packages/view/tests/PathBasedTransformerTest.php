<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\PathBasedTransformer;

final class PathBasedTransformerTest extends TestCase
{
    public function test_appends_query_params(): void
    {
        $t = new PathBasedTransformer();
        $url = $t->transform('/uploads/hero.jpg', 480, 'webp', 75);
        $this->assertSame('/uploads/hero.jpg?w=480&fm=webp&q=75', $url);
    }

    public function test_appends_with_ampersand_when_query_exists(): void
    {
        $t = new PathBasedTransformer();
        $url = $t->transform('/uploads/hero.jpg?v=2', 768, 'avif', 80);
        $this->assertSame('/uploads/hero.jpg?v=2&w=768&fm=avif&q=80', $url);
    }
}
