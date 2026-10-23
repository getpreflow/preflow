<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
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
}
