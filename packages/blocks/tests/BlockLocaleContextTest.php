<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use Preflow\Blocks\BlockLocale;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Data\Locales;

/** Blocks render in the render context's locale (ContextBlockLocale); a BlockLocale without it still works, reported once. */
final class BlockLocaleContextTest extends BlocksAppTestCase
{
    private const array PAGE = [
        ['_uid' => 'b1', '_block' => 'banner', 'title' => ['en' => 'Hello', 'de' => 'Hallo']],
    ];

    protected function tearDown(): void
    {
        Deprecations::reset();
        parent::tearDown();
    }

    public function test_block_locale_comes_from_the_context(): void
    {
        $this->assertStringContainsString('<h2>Hallo</h2>', $this->render('{{ blocks(page) }}', ['page' => self::PAGE], 'de'));
        $this->assertStringContainsString('<h2>Hello</h2>', $this->render('{{ blocks(page) }}', ['page' => self::PAGE], 'en'));

        // A stretch of a render in another locale (within) renders its blocks in it, and the render's own is back after it.
        $html = Contexts::run($this->visit()->in('en'), fn (): string => Contexts::within(Contexts::current()->in('de'), fn (): string => $this->render('{{ blocks(page) }}', ['page' => self::PAGE]))
            . $this->render('{{ blocks(page) }}', ['page' => self::PAGE]));
        $this->assertSame(1, substr_count($html, '<h2>Hallo</h2>'));
        $this->assertSame(1, substr_count($html, '<h2>Hello</h2>'));
    }

    public function test_a_block_locale_without_the_context_form_is_asked_get_and_reported_once(): void
    {
        $c = $this->app()->container();
        $c->instance(BlockLocale::class, new class ($c->get(Locales::class)) implements BlockLocale {
            public function __construct(private readonly Locales $locales) {}

            public function get(): string
            {
                return 'de';
            }

            public function locales(): Locales
            {
                return $this->locales;
            }
        });
        $reported = [];
        set_error_handler(static function (int $level, string $message) use (&$reported): bool {
            $reported[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $html = $this->render('{{ blocks(page) }}{{ blocks(page) }}', ['page' => self::PAGE], 'en');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(2, substr_count($html, '<h2>Hallo</h2>'), 'its own locale');
        $this->assertSame(['Preflow\Blocks\BlockLocale::get() is deprecated since 0.81 and is removed in 0.83; use ContextBlockLocale::localeOf().'], $reported);
    }
}
