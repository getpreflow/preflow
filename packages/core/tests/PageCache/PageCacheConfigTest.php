<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\PageCacheConfig;

final class PageCacheConfigTest extends TestCase
{
    public function test_every_key_is_read(): void
    {
        $config = PageCacheConfig::fromArray([
            'enabled' => true,
            'max_age' => 60,
            'rebuild_budget' => 1,
            'cache_query' => false,
            'ignore_query' => ['ref', 'ref'],
            'vary_headers' => ['HX-Request', 'X-Gallery-Load'],
            'signed_in_cookies' => ['preflow_auth'],
        ]);

        $this->assertTrue($config->enabled);
        $this->assertSame(60, $config->maxAge);
        $this->assertSame(1.0, $config->rebuildBudget);
        $this->assertFalse($config->cacheQuery);
        $this->assertSame(['ref'], $config->ignoreQuery);
        $this->assertSame(['HX-Request', 'X-Gallery-Load'], $config->varyHeaders);
        $this->assertSame(['preflow_auth'], $config->signedInCookies);
    }

    public function test_hosts_and_limits(): void
    {
        $default = PageCacheConfig::fromArray([]);
        $this->assertSame([], $default->hosts);
        $this->assertSame(50, $default->maxQueryVariants);
        $this->assertSame(50000, $default->maxPages);

        $config = PageCacheConfig::fromArray(['hosts' => ['WWW.Site.example'], 'max_query_variants' => 5, 'max_pages' => 10], 'https://site.example');
        $this->assertSame(['site.example', 'www.site.example'], $config->hosts);
        $this->assertTrue($config->cachesHost('Site.Example'));
        $this->assertFalse($config->cachesHost('alias.example'));
        $this->assertSame(5, $config->maxQueryVariants);
        $this->assertSame(10, $config->maxPages);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function wrong(): iterable
    {
        yield 'enabled' => [['enabled' => 'yes']];
        yield 'max_age' => [['max_age' => -1]];
        yield 'max_age float' => [['max_age' => 1.5]];
        yield 'rebuild_budget' => [['rebuild_budget' => '2']];
        yield 'ignore_query' => [['ignore_query' => 'utm_*']];
        yield 'vary_headers item' => [['vary_headers' => ['']]];
        yield 'hosts' => [['hosts' => 'example.com']];
        yield 'max_query_variants' => [['max_query_variants' => 0]];
        yield 'max_pages' => [['max_pages' => '100']];
    }

    #[DataProvider('wrong')]
    public function test_a_wrong_value_is_reported(array $config): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('page_cache.' . array_key_first($config));

        PageCacheConfig::fromArray($config);
    }
}
