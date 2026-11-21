<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Data\Locales;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Testing\ContextFactory;

/** The content locale is the render's locale (since 0.81); blocks ask it with the context (ContextBlockLocale). */
final class ContentLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        Contexts::reset();
    }

    private static function context(): RenderContext
    {
        return ContextFactory::forTest(new Site('http://localhost', ['en', 'de'], 'en'));
    }

    public function test_it_is_the_renders_locale_when_it_is_a_content_locale(): void
    {
        $locale = new ContentLocale(new Locales(['en', 'de'], 'en'));

        $this->assertSame('en', $locale->get(), 'outside every render: the default');
        $this->assertSame('de', Contexts::run(self::context()->in('de'), $locale->get(...)));
        $this->assertSame('en', Contexts::run(self::context()->in('fr'), $locale->get(...)), 'not a content locale: the default');
    }

    public function test_blocks_get_the_locale_of_the_context_they_are_given(): void
    {
        $locale = new ContentLocale(new Locales(['en', 'de'], 'en'));

        $this->assertSame('de', $locale->localeOf(self::context()->in('de')));
        $this->assertSame('en', $locale->localeOf(self::context()->in('fr')), 'not a content locale: the default');
    }
}
