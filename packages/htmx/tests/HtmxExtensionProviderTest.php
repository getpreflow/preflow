<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Htmx\HtmxExtensionProvider;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\Htmx\ComponentToken;
use Preflow\View\TemplateFunctionDefinition;

final class HtmxExtensionProviderTest extends TestCase
{
    public function test_provides_hd_function(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());
        $token = new ComponentToken('test-secret-key-32-chars-long!!');
        $provider = new HtmxExtensionProvider($driver, $token);
        $functions = $provider->getTemplateFunctions();

        $this->assertCount(1, $functions);
        $this->assertSame('hd', $functions[0]->name);
        $this->assertTrue($functions[0]->isSafe);
    }

    public function test_provides_hd_global(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());
        $token = new ComponentToken('test-secret-key-32-chars-long!!');
        $provider = new HtmxExtensionProvider($driver, $token);
        $globals = $provider->getTemplateGlobals();

        $this->assertArrayHasKey('hd', $globals);
        $this->assertSame($provider, $globals['hd']);
    }

    public function test_post_returns_html_attributes(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());
        $token = new ComponentToken('test-secret-key-32-chars-long!!');
        $provider = new HtmxExtensionProvider($driver, $token);

        $result = $provider->post('increment', 'App\\Counter', 'Counter-abc123', []);

        $this->assertStringContainsString('hx-post=', $result);
    }
}
