<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\PathBasedTransformer;
use Preflow\View\ResponsiveImage;

final class ResponsiveImageTest extends TestCase
{
    private ResponsiveImage $helper;

    protected function setUp(): void
    {
        $this->helper = new ResponsiveImage(new PathBasedTransformer());
    }

    public function test_renders_img_with_srcset(): void
    {
        $html = $this->helper->render('/uploads/hero.jpg', [
            'widths' => [480, 1024], 'alt' => 'Welcome', 'sizes' => '100vw',
        ]);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('480w', $html);
        $this->assertStringContainsString('1024w', $html);
        $this->assertStringContainsString('alt="Welcome"', $html);
        $this->assertStringContainsString('sizes="100vw"', $html);
    }

    public function test_src_uses_largest_width(): void
    {
        $html = $this->helper->render('/img.jpg', ['widths' => [300, 600, 900]]);
        $this->assertStringContainsString('src="/img.jpg?w=900', $html);
    }

    public function test_default_format_is_webp(): void
    {
        $html = $this->helper->render('/img.jpg', ['widths' => [480]]);
        $this->assertStringContainsString('fm=webp', $html);
    }

    public function test_custom_format(): void
    {
        $html = $this->helper->render('/img.jpg', ['widths' => [480], 'format' => 'avif']);
        $this->assertStringContainsString('fm=avif', $html);
    }

    public function test_loading_defaults_to_lazy(): void
    {
        $html = $this->helper->render('/img.jpg', ['widths' => [480]]);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function test_custom_attrs_passed_through(): void
    {
        $html = $this->helper->render('/img.jpg', ['widths' => [480], 'attrs' => ['data-zoom' => 'true']]);
        $this->assertStringContainsString('data-zoom="true"', $html);
    }

    public function test_srcset_only(): void
    {
        $srcset = $this->helper->srcset('/img.jpg', [480, 1024], 'webp', 75);
        $this->assertStringContainsString('/img.jpg?w=480&fm=webp&q=75 480w', $srcset);
        $this->assertStringContainsString('/img.jpg?w=1024&fm=webp&q=75 1024w', $srcset);
    }

    public function test_preset_overrides_defaults(): void
    {
        $helper = new ResponsiveImage(new PathBasedTransformer(), [
            'hero' => ['widths' => [800, 1600], 'sizes' => '100vw', 'loading' => 'eager'],
        ]);
        $html = $helper->render('/img.jpg', ['preset' => 'hero']);
        $this->assertStringContainsString('800w', $html);
        $this->assertStringContainsString('1600w', $html);
        $this->assertStringContainsString('loading="eager"', $html);
    }
}
