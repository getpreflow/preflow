<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Helpers;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Helpers\HelpersExtensionProvider;
use Preflow\View\PathBasedTransformer;
use Preflow\View\ResponsiveImage;
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class HelpersExtensionProviderTest extends TestCase
{
    private HelpersExtensionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new HelpersExtensionProvider(
            new ResponsiveImage(new PathBasedTransformer()),
        );
    }

    public function test_implements_template_extension_provider(): void
    {
        $this->assertInstanceOf(TemplateExtensionProvider::class, $this->provider);
    }

    public function test_registers_color_functions(): void
    {
        $names = array_map(
            fn (TemplateFunctionDefinition $f) => $f->name,
            $this->provider->getTemplateFunctions(),
        );
        $this->assertContains('color_lighten', $names);
        $this->assertContains('color_darken', $names);
        $this->assertContains('color_contrast', $names);
        $this->assertContains('color_adjust_contrast', $names);
    }

    public function test_registers_image_functions(): void
    {
        $names = array_map(
            fn (TemplateFunctionDefinition $f) => $f->name,
            $this->provider->getTemplateFunctions(),
        );
        $this->assertContains('responsive_image', $names);
        $this->assertContains('image_srcset', $names);
    }

    public function test_color_lighten_callable(): void
    {
        $fn = $this->findFunction('color_lighten');
        $result = ($fn->callable)('#000000', 0.5);
        $this->assertSame('#808080', $result);
    }

    public function test_responsive_image_callable(): void
    {
        $fn = $this->findFunction('responsive_image');
        $result = ($fn->callable)('/img.jpg', ['widths' => [480], 'alt' => 'Test']);
        $this->assertStringContainsString('<img', $result);
    }

    public function test_image_srcset_callable(): void
    {
        $fn = $this->findFunction('image_srcset');
        $result = ($fn->callable)('/img.jpg', ['widths' => [480, 1024]]);
        $this->assertStringContainsString('480w', $result);
        $this->assertStringContainsString('1024w', $result);
    }

    public function test_works_without_responsive_image(): void
    {
        $provider = new HelpersExtensionProvider(null);
        $names = array_map(
            fn (TemplateFunctionDefinition $f) => $f->name,
            $provider->getTemplateFunctions(),
        );
        $this->assertContains('color_lighten', $names);
        $this->assertNotContains('responsive_image', $names);
    }

    private function findFunction(string $name): TemplateFunctionDefinition
    {
        foreach ($this->provider->getTemplateFunctions() as $f) {
            if ($f->name === $name) {
                return $f;
            }
        }
        $this->fail("Function {$name} not found");
    }
}
