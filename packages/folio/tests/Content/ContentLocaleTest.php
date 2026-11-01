<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Folio\Content\ContentLocale;

final class ContentLocaleTest extends TestCase
{
    public function test_scoped_puts_the_locale_back(): void
    {
        $locale = new ContentLocale(new Locales(['en', 'de'], 'en'));
        $locale->set('de');

        $seen = $locale->scoped(function () use ($locale): string {
            $locale->set('en');

            return $locale->get();
        });

        $this->assertSame('en', $seen);
        $this->assertSame('de', $locale->get());
    }

    public function test_scoped_puts_the_locale_back_when_the_render_throws(): void
    {
        $locale = new ContentLocale(new Locales(['en', 'de'], 'en'));
        $locale->set('de');

        try {
            $locale->scoped(function () use ($locale): void {
                $locale->set('en');
                throw new \RuntimeException('render failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('de', $locale->get());
    }
}
