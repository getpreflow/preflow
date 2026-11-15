<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Data\Locales;
use Preflow\Members\Links;

/** Member page links: absolute for mail, host-less for the pages, prefixed by locale. */
final class LinksTest extends TestCase
{
    private function links(bool $prefixed = true): Links
    {
        return new Links('https://site.test/', '/konto', new Locales(['en', 'de'], 'en'), $prefixed);
    }

    public function test_url_is_absolute_and_local_is_not(): void
    {
        $links = $this->links();

        $this->assertSame('https://site.test/de/konto/login', $links->url('de', '/login'));
        $this->assertSame('/de/konto/login', $links->local('de', '/login'));
        $this->assertSame('/konto', $links->local('en'));
        $this->assertSame('/konto', $links->local('xx'), 'an unknown locale gets the default');
    }

    public function test_localize_prefixes_a_site_path_by_locale(): void
    {
        $this->assertSame('/de/start', $this->links()->localize('de', '/start'));
        $this->assertSame('/start', $this->links()->localize('en', '/start'));
        $this->assertSame('/start', $this->links(false)->localize('de', '/start'), 'no prefix without the prefix strategy');
        $this->assertSame('/konto/login', $this->links(false)->local('de', '/login'));
    }

    public function test_default_locale_link_carries_prefix_with_prefix_default(): void
    {
        $links = new Links('https://site.test/', '/members', new Locales(['en', 'de'], 'en'), true, new LocaleUrls(['en', 'de'], 'en', true, true));

        $this->assertSame('https://site.test/en/members/login', $links->url('en', '/login'));
        $this->assertSame('/de/members/login', $links->local('de', '/login'));
        $this->assertSame('/en/members', $links->local('xx'), 'an unknown locale gets the default, with its prefix');
        $this->assertSame('/en/start', $links->localize('en', '/start'));
        $this->assertSame('/de/start', $links->localize('de', '/start'));
    }

    public function test_links_unchanged_without_locale_urls(): void
    {
        $plain = new Links('https://site.test/', '/members', new Locales(['en', 'de'], 'en'), true);
        $default = new Links('https://site.test/', '/members', new Locales(['en', 'de'], 'en'), true, new LocaleUrls(['en', 'de'], 'en'));

        foreach ([$plain, $default] as $links) {
            $this->assertSame('https://site.test/members/login', $links->url('en', '/login'));
            $this->assertSame('/de/members/login', $links->local('de', '/login'));
            $this->assertSame('/start', $links->localize('en', '/start'));
        }
    }

    public function test_url_strategy_none_gives_no_prefix_with_locale_urls(): void
    {
        $links = new Links('https://site.test/', '/members', new Locales(['en', 'de'], 'en'), false, new LocaleUrls(['en', 'de'], 'en', false));

        $this->assertSame('/members/login', $links->local('de', '/login'));
    }
}
