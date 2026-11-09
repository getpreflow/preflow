<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Api;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Api\ApiConfig;
use Psr\Http\Message\ServerRequestInterface;

final class ApiConfigTest extends TestCase
{
    private function request(string $path, ?string $accept = null): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $path);

        return $accept === null ? $request : $request->withHeader('Accept', $accept);
    }

    public function test_is_api_matches_whole_path_segments(): void
    {
        $config = new ApiConfig();

        $this->assertTrue($config->isApi($this->request('/api')));
        $this->assertTrue($config->isApi($this->request('/api/jobs')));
        $this->assertFalse($config->isApi($this->request('/apix')));
        $this->assertFalse($config->isApi($this->request('/jobs')));
    }

    public function test_wants_json_for_an_api_path_whatever_the_accept(): void
    {
        $this->assertTrue((new ApiConfig())->wantsJson($this->request('/api/jobs', 'text/html')));
    }

    public function test_wants_json_for_a_json_accept(): void
    {
        $config = new ApiConfig();

        $this->assertTrue($config->wantsJson($this->request('/jobs', 'application/json')));
        $this->assertTrue($config->wantsJson($this->request('/jobs', 'application/vnd.x+json')));
    }

    public function test_browser_and_wildcard_accepts_mean_html(): void
    {
        $config = new ApiConfig();

        $this->assertFalse($config->wantsJson($this->request('/jobs', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')));
        $this->assertFalse($config->wantsJson($this->request('/jobs', '*/*')));
        $this->assertFalse($config->wantsJson($this->request('/jobs', 'application/json, text/html')));
        $this->assertFalse($config->wantsJson($this->request('/jobs')));
    }

    public function test_from_array_null_gives_the_defaults(): void
    {
        $this->assertEquals(new ApiConfig(), ApiConfig::fromArray(null));
    }

    public function test_empty_prefixes_opt_out_of_the_api(): void
    {
        $config = ApiConfig::fromArray(['prefixes' => []]);

        $this->assertSame([], $config->prefixes);
        $this->assertFalse($config->isApi(new \Nyholm\Psr7\ServerRequest('GET', '/api/jobs')));
    }

    public function test_from_array_reads_the_file_shape(): void
    {
        $config = ApiConfig::fromArray([
            'prefixes' => ['/v1/', '/rest'],
            'cors' => [
                'origins' => ['https://a.test'],
                'methods' => ['GET'],
                'headers' => ['X-A'],
                'expose' => ['X-B'],
                'max_age' => 60,
                'credentials' => true,
            ],
        ]);

        $this->assertSame(['/v1', '/rest'], $config->prefixes);
        $this->assertSame(['https://a.test'], $config->corsOrigins);
        $this->assertSame(['GET'], $config->corsMethods);
        $this->assertSame(['X-A'], $config->corsHeaders);
        $this->assertSame(['X-B'], $config->corsExpose);
        $this->assertSame(60, $config->corsMaxAge);
        $this->assertTrue($config->corsCredentials);
    }

    public function test_wildcard_origin_with_credentials_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cors.credentials');

        ApiConfig::fromArray(['cors' => ['origins' => ['*'], 'credentials' => true]]);
    }
}
