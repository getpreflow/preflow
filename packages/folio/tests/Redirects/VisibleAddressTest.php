<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Redirects;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Redirects\VisibleAddress;

/** A stored address as visitors see it: the default language's prefix only with prefix_default. */
final class VisibleAddressTest extends TestCase
{
    public function test_default_locale_address_carries_prefix_with_prefix_default(): void
    {
        $urls = new \Preflow\Core\Http\LocaleUrls(['en', 'de'], 'en', prefixDefault: true);
        $visible = new VisibleAddress('en', true, $urls->prefix(...));

        $this->assertSame('/en/about', $visible->of('en', '/about'));
        $this->assertSame('/en', $visible->of('en', '/'));
        $this->assertSame('/de/über-uns', $visible->of('de', '/%C3%BCber-uns'));
        $this->assertSame('/about', $visible->of('', '/about'), 'a language-neutral entry has no prefix');
    }

    public function test_default_locale_address_unprefixed_by_default(): void
    {
        $visible = new VisibleAddress('en', true);
        $this->assertSame('/about', $visible->of('en', '/about'));
        $this->assertSame('/de/about', $visible->of('de', '/about'));

        $site = new VisibleAddress('en', true, (new \Preflow\Core\Http\LocaleUrls(['en', 'de'], 'en'))->prefix(...));
        $this->assertSame('/about', $site->of('en', '/about'), 'the site rule without prefix_default');
        $this->assertSame('/de/about', $site->of('de', '/about'));
        $this->assertSame('/about', (new VisibleAddress('en', false, static fn (string $locale): string => '/' . $locale))->of('de', '/about'), 'no prefixes without the prefix strategy');
    }
}
