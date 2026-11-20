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

/** The content locale is the render's locale (since 0.81); scoped() puts the context back. */
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

    public function test_scoped_puts_the_locale_back(): void
    {
        $locale = new ContentLocale(new Locales(['en', 'de'], 'en'));

        Contexts::run(self::context()->in('de'), function () use ($locale): void {
            $seen = $locale->scoped(static function () use ($locale): string {
                Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->in('en'));

                return $locale->get();
            });

            $this->assertSame('en', $seen);
            $this->assertSame('de', $locale->get());
        });
    }

    public function test_scoped_puts_the_locale_back_when_the_render_throws(): void
    {
        $locale = new ContentLocale(new Locales(['en', 'de'], 'en'));

        Contexts::run(self::context()->in('de'), function () use ($locale): void {
            try {
                $locale->scoped(static function (): void {
                    Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->in('en'));
                    throw new \RuntimeException('render failed');
                });
            } catch (\RuntimeException) {
            }

            $this->assertSame('de', $locale->get());
        });
    }
}
