<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\Htmx\SwapStrategy;
use Preflow\Twig\HdExtension;

final class HdExtensionTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $headers = new ResponseHeaders();
        $driver = new HtmxDriver($headers);
        $token = new ComponentToken('test-key-for-hd-extension-32ch!');

        $extension = new HdExtension($driver, $token, '/--component');

        $this->twig = new Environment(new ArrayLoader([]), ['autoescape' => false]);
        $this->twig->addExtension($extension);
    }

    private function render(string $template, array $context = []): string
    {
        return $this->twig->createTemplate($template)->render($context);
    }

    public function test_hd_post_generates_attributes(): void
    {
        $result = $this->render(
            "{{ hd.post('refresh', componentClass, componentId, props) }}",
            [
                'componentClass' => 'App\\Hero',
                'componentId' => 'hero-abc',
                'props' => [],
            ]
        );

        $this->assertStringContainsString('hx-post=', $result);
        $this->assertStringContainsString('hx-target="#hero-abc"', $result);
        $this->assertStringContainsString('hx-swap="outerHTML"', $result);
        $this->assertStringContainsString('token=', $result);
    }

    public function test_hd_get_generates_attributes(): void
    {
        $result = $this->render(
            "{{ hd.get('render', componentClass, componentId, props) }}",
            [
                'componentClass' => 'App\\Hero',
                'componentId' => 'hero-abc',
                'props' => [],
            ]
        );

        $this->assertStringContainsString('hx-get=', $result);
    }

    public function test_hd_on_generates_listen_attributes(): void
    {
        $result = $this->render(
            "{{ hd.on('cartUpdated', componentClass, componentId, props) }}",
            [
                'componentClass' => 'App\\Cart',
                'componentId' => 'cart-1',
                'props' => [],
            ]
        );

        $this->assertStringContainsString('hx-trigger="cartUpdated from:body"', $result);
        $this->assertStringContainsString('hx-get=', $result);
        $this->assertStringContainsString('hx-target="#cart-1"', $result);
    }

    public function test_hd_asset_tag(): void
    {
        $result = $this->render("{{ hd.assetTag() }}");

        $this->assertStringContainsString('htmx.org', $result);
    }

    public function test_token_is_signed_in_url(): void
    {
        $result = $this->render(
            "{{ hd.post('refresh', componentClass, componentId, props) }}",
            [
                'componentClass' => 'App\\Hero',
                'componentId' => 'hero-1',
                'props' => ['id' => '42'],
            ]
        );

        // URL should contain a token parameter
        $this->assertStringContainsString('/--component', $result);
        $this->assertStringContainsString('token=', $result);
    }
}
