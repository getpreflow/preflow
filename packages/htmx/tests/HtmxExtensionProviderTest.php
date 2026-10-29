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

    public function test_asset_tag_marks_htmx_as_needed(): void
    {
        $assets = new \Preflow\Htmx\HtmxAssets(new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator()), 'on-demand');
        $provider = new HtmxExtensionProvider(new HtmxDriver(new ResponseHeaders()), new ComponentToken('k'), assets: $assets);

        $this->assertSame('', $provider->assetTag());
        $this->assertTrue($assets->isRequired());
    }

    public function test_action_url_carries_a_decodable_token(): void
    {
        $token = new ComponentToken('test-secret-key-32-chars-long!!');
        $provider = new HtmxExtensionProvider(new HtmxDriver(new ResponseHeaders()), $token);

        $url = $provider->actionUrl('save', \stdClass::class, ['id' => 7]);

        $this->assertStringStartsWith('/--component/action?token=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $payload = $token->decode($query['token']);
        $this->assertSame('save', $payload->action);
        $this->assertSame(['id' => 7], $payload->props);
    }

    public function test_urls_start_with_the_path_prefix(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());
        $token = new ComponentToken('test-secret-key-32-chars-long!!');
        $prefixed = new HtmxExtensionProvider($driver, $token, pathPrefix: fn (): string => '/de');

        $this->assertStringStartsWith('/de/--component/action?token=', $prefixed->actionUrl('save', \stdClass::class));
        $this->assertStringContainsString('hx-post="/de/--component/action', $prefixed->post('save', \stdClass::class, 'C-1'));
        $this->assertStringContainsString('hx-get="/de/--component/action', $prefixed->get('save', \stdClass::class, 'C-1'));
        $this->assertStringContainsString('/de/--component/render', $prefixed->on('saved', \stdClass::class, 'C-1'));

        $plain = new HtmxExtensionProvider($driver, $token);
        $this->assertStringStartsWith('/--component/action?token=', $plain->actionUrl('save', \stdClass::class));
        $this->assertStringContainsString('hx-post="/--component/action', $plain->post('save', \stdClass::class, 'C-1'));
        $this->assertStringContainsString('"/--component/render', $plain->on('saved', \stdClass::class, 'C-1'));
    }
}
