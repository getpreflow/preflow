<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\LocaleUrls;

final class LocaleUrlsTest extends TestCase
{
    public function test_default_has_no_prefix_by_default(): void
    {
        $urls = new LocaleUrls(['en', 'de'], 'en');

        $this->assertSame('', $urls->prefix('en'));
        $this->assertSame('/de', $urls->prefix('de'));
        $this->assertSame('/faq', $urls->path('en', '/faq'));
        $this->assertSame('/', $urls->path('en', '/'));
        $this->assertSame('/de', $urls->path('de', '/'));
        $this->assertSame('/de', $urls->path('de', ''));
        $this->assertSame('/de/faq', $urls->path('de', 'faq/'));
    }

    public function test_prefix_default_prefixes_every_locale(): void
    {
        $urls = new LocaleUrls(['en', 'de'], 'en', prefixDefault: true);

        $this->assertSame('/en', $urls->prefix('en'));
        $this->assertSame('/en', $urls->path('en', '/'));
        $this->assertSame('/en/faq', $urls->path('en', '/faq'));
        $this->assertSame('/de/faq', $urls->path('de', '/faq'));
    }

    public function test_no_prefixes_with_strategy_none(): void
    {
        $urls = new LocaleUrls(['en', 'de'], 'en', prefixed: false);

        $this->assertSame('', $urls->prefix('de'));
        $this->assertSame('', $urls->prefix('en'));
        $this->assertSame('/faq', $urls->path('de', '/faq'));
        $this->assertNull($urls->localeOf('/de/faq'));
        $this->assertSame('/de/faq', $urls->strip('/de/faq'));
    }

    public function test_unknown_locale_has_no_prefix(): void
    {
        $urls = new LocaleUrls(['en', 'de'], 'en');

        $this->assertSame('', $urls->prefix('fr'));
        $this->assertSame('/faq', $urls->path('fr', '/faq'));
    }

    public function test_locale_of_and_strip(): void
    {
        $urls = new LocaleUrls(['en', 'de'], 'en');

        $this->assertSame('en', $urls->localeOf('/en/faq'));
        $this->assertSame('de', $urls->localeOf('/de'));
        $this->assertSame('de', $urls->localeOf('/de/'));
        $this->assertNull($urls->localeOf('/fr/x'));
        $this->assertNull($urls->localeOf('/'));
        $this->assertNull($urls->localeOf('/deutsch'));
        $this->assertSame('/', $urls->strip('/de'));
        $this->assertSame('/', $urls->strip('/de/'));
        $this->assertSame('/faq', $urls->strip('/de/faq'));
        $this->assertSame('/faq', $urls->strip('/faq'));
        $this->assertSame('/deutsch', $urls->strip('/deutsch'));
    }

    public function test_from_config_defaults(): void
    {
        $urls = LocaleUrls::fromConfig(['default' => 'en', 'available' => ['en', 'de']]);

        $this->assertSame(['en', 'de'], $urls->locales);
        $this->assertSame('en', $urls->default);
        $this->assertTrue($urls->prefixed);
        $this->assertFalse($urls->prefixDefault);
        $this->assertFalse($urls->redirectBare);

        $none = LocaleUrls::fromConfig(null, 'fr');

        $this->assertSame(['fr'], $none->locales);
        $this->assertSame('fr', $none->default);

        $this->assertFalse(LocaleUrls::fromConfig(['available' => ['en'], 'url_strategy' => 'none'])->prefixed);
    }

    public function test_from_config_reads_the_flags(): void
    {
        $urls = LocaleUrls::fromConfig(['default' => 'en', 'available' => ['en', 'de'], 'prefix_default' => true, 'redirect_bare' => true]);

        $this->assertTrue($urls->prefixDefault);
        $this->assertTrue($urls->redirectBare);
    }

    public function test_from_config_refuses_prefix_default_without_prefix_strategy(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/i18n.php: prefix_default needs url_strategy "prefix".');

        LocaleUrls::fromConfig(['available' => ['en', 'de'], 'url_strategy' => 'none', 'prefix_default' => true]);
    }

    public function test_from_config_refuses_redirect_bare_without_prefix_strategy(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/i18n.php: redirect_bare needs url_strategy "prefix".');

        LocaleUrls::fromConfig(['available' => ['en', 'de'], 'url_strategy' => 'none', 'redirect_bare' => true]);
    }

    public function test_from_config_refuses_non_bool_flags(): void
    {
        try {
            LocaleUrls::fromConfig(['available' => ['en', 'de'], 'prefix_default' => 'yes']);
            $this->fail('prefix_default "yes" was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('config/i18n.php: prefix_default must be true or false.', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/i18n.php: redirect_bare must be true or false.');

        LocaleUrls::fromConfig(['available' => ['en', 'de'], 'redirect_bare' => 1]);
    }

    public function test_false_flags_are_allowed_with_strategy_none(): void
    {
        $urls = LocaleUrls::fromConfig(['available' => ['en'], 'url_strategy' => 'none', 'prefix_default' => false, 'redirect_bare' => false]);

        $this->assertFalse($urls->prefixDefault);
        $this->assertFalse($urls->redirectBare);
    }
}
