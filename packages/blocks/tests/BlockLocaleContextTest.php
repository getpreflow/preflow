<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use Preflow\Core\Context\Contexts;

/** Blocks render in the render context's locale (BlockLocale::localeOf()). */
final class BlockLocaleContextTest extends BlocksAppTestCase
{
    private const array PAGE = [
        ['_uid' => 'b1', '_block' => 'banner', 'title' => ['en' => 'Hello', 'de' => 'Hallo']],
    ];

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
}
