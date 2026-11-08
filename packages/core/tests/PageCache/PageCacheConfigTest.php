<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    public function test_max_age_zero_means_no_maximum_age_and_a_negative_one_is_refused(): void
    {
        $this->assertSame(0, PageCacheConfig::fromArray(['max_age' => 0])->maxAge);
        $this->assertSame(14400, PageCacheConfig::fromArray([])->maxAge);
        $this->expectException(\InvalidArgumentException::class);
        new PageCacheConfig(maxAge: -1);
    }

    public function test_explain_is_off_and_verify_first_by_default(): void
    {
        $default = PageCacheConfig::fromArray([]);
        $this->assertFalse($default->explain);
        $this->assertSame('first', $default->verify);

        $config = PageCacheConfig::fromArray(['explain' => true, 'verify' => 'always'])->withSignedInCookies(['preflow_auth']);
        $this->assertTrue($config->explain);
        $this->assertSame('always', $config->verify);
        $this->assertSame('always', PageCacheConfig::fromArray(['verify' => true])->verify);
        $this->assertSame('off', PageCacheConfig::fromArray(['verify' => false])->verify);
        $this->assertSame('off', $config->withVerify('off')->verify);

        $this->expectException(\InvalidArgumentException::class);
        PageCacheConfig::fromArray(['verify' => 'yes']);
    }

    #[RunInSeparateProcess]
    public function test_an_enabled_cache_without_a_host_is_logged_once(): void
    {
        $log = sys_get_temp_dir() . '/pagecache_log_' . bin2hex(random_bytes(4));
        ini_set('error_log', $log);
        try {
            PageCacheConfig::fromArray(['enabled' => false], 'example.com'); // off: nothing to say
            $this->assertFileDoesNotExist($log);

            $config = PageCacheConfig::fromArray(['enabled' => true], 'example.com');
            PageCacheConfig::fromArray(['enabled' => true]);

            $this->assertSame([], $config->hosts);
            $text = (string) file_get_contents($log);
            $this->assertStringContainsString("app.url 'example.com' has no scheme or host", $text);
            $this->assertSame(1, substr_count($text, 'caches nothing'));
        } finally {
            @unlink($log);
        }
    }

    #[RunInSeparateProcess]
    public function test_an_enabled_cache_with_a_host_logs_nothing(): void
    {
        $log = sys_get_temp_dir() . '/pagecache_log_' . bin2hex(random_bytes(4));
        ini_set('error_log', $log);
        PageCacheConfig::fromArray(['enabled' => true], 'https://example.com');
        PageCacheConfig::fromArray(['enabled' => true, 'hosts' => ['example.com']]);

        $this->assertFileDoesNotExist($log);
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
