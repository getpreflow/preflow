<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\AcceptLanguage;

/** The best offered language for a browser's Accept-Language header. */
final class AcceptLanguageTest extends TestCase
{
    public function test_the_best_offered_language_wins(): void
    {
        $this->assertSame('fr', AcceptLanguage::best('fr-CH,fr;q=0.9', ['en', 'de', 'fr']));
        $this->assertSame('de', AcceptLanguage::best('it;q=1, de-DE;q=0.8, en;q=0.5', ['en', 'de']));
        $this->assertSame('en', AcceptLanguage::best('EN-us', ['en', 'de']));
        $this->assertSame('de', AcceptLanguage::best('en;q=0.2, de', ['en', 'de']), 'by quality, not by order');
    }

    public function test_nothing_offered_or_no_header_gives_null(): void
    {
        $this->assertNull(AcceptLanguage::best('', ['en']));
        $this->assertNull(AcceptLanguage::best('it, es;q=0.5', ['en', 'de']));
        $this->assertNull(AcceptLanguage::best('fr;q=0', ['fr']), 'q=0 means not acceptable');
        $this->assertNull(AcceptLanguage::best('*', ['en']));
    }
}
