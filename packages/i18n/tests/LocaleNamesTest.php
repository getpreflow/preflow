<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\LocaleNames;

/** A language's name in that language itself, for language switches. */
final class LocaleNamesTest extends TestCase
{
    public function test_delivered_languages_have_their_own_names(): void
    {
        $this->assertSame('English', LocaleNames::native('en'));
        $this->assertSame('Deutsch', LocaleNames::native('de'));
        $this->assertSame('Français', LocaleNames::native('fr'));
        $this->assertSame('Italiano', LocaleNames::native('it'));
    }

    public function test_an_unlisted_code_gets_a_readable_name(): void
    {
        $name = LocaleNames::native('fi');
        $this->assertSame(extension_loaded('intl') ? 'Suomi' : 'FI', $name);
        $this->assertSame('XQ', LocaleNames::native('xq'), 'unknown even to intl: the code');
    }
}
