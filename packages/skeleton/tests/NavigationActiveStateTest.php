<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Components\Navigation\Navigation;
use Preflow\Core\Http\RequestContext;

final class NavigationActiveStateTest extends TestCase
{
    private function createNavigation(string $path, array $items): Navigation
    {
        $context = new RequestContext(path: $path, method: 'GET');
        $nav = new Navigation($context);
        $nav->setProps(['items' => $items, 'brand' => 'Test']);
        $nav->resolveState();
        return $nav;
    }

    public function test_exact_match_for_home(): void
    {
        $nav = $this->createNavigation('/', [
            ['path' => '/', 'label' => 'Home'],
            ['path' => '/blog', 'label' => 'Blog'],
        ]);

        $this->assertTrue($nav->items[0]['active']);
        $this->assertFalse($nav->items[1]['active']);
    }

    public function test_home_not_active_on_other_pages(): void
    {
        $nav = $this->createNavigation('/blog', [
            ['path' => '/', 'label' => 'Home'],
            ['path' => '/blog', 'label' => 'Blog'],
        ]);

        $this->assertFalse($nav->items[0]['active']);
        $this->assertTrue($nav->items[1]['active']);
    }

    public function test_prefix_match_for_subpages(): void
    {
        $nav = $this->createNavigation('/blog/my-post', [
            ['path' => '/', 'label' => 'Home'],
            ['path' => '/blog', 'label' => 'Blog'],
            ['path' => '/about', 'label' => 'About'],
        ]);

        $this->assertFalse($nav->items[0]['active']);
        $this->assertTrue($nav->items[1]['active']);
        $this->assertFalse($nav->items[2]['active']);
    }

    public function test_no_match_all_inactive(): void
    {
        $nav = $this->createNavigation('/contact', [
            ['path' => '/', 'label' => 'Home'],
            ['path' => '/blog', 'label' => 'Blog'],
        ]);

        $this->assertFalse($nav->items[0]['active']);
        $this->assertFalse($nav->items[1]['active']);
    }

    public function test_empty_items(): void
    {
        $nav = $this->createNavigation('/', []);

        $this->assertSame([], $nav->items);
    }

    public function test_brand_prop(): void
    {
        $nav = $this->createNavigation('/', []);

        $this->assertSame('Test', $nav->brand);
    }

    public function test_brand_defaults_to_empty(): void
    {
        $context = new RequestContext(path: '/', method: 'GET');
        $nav = new Navigation($context);
        $nav->setProps(['items' => []]);
        $nav->resolveState();

        $this->assertSame('', $nav->brand);
    }

    public function test_prefix_does_not_match_sibling_paths(): void
    {
        $nav = $this->createNavigation('/blogroll', [
            ['path' => '/blog', 'label' => 'Blog'],
        ]);

        $this->assertFalse($nav->items[0]['active']);
    }
}
