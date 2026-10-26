<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Translations;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Translations\StoredOverrides;

/** Override texts are plain text: nothing a browser reads as markup or as an entity. */
final class StoredOverridesTextTest extends TestCase
{
    public function test_entity_shaped_text_is_refused(): void
    {
        foreach (['&#60;img src=x&#62;', 'a &#x3C; b', '&lt;b&gt;bold', 'Save &amp; close', 'x &QUOT; y', '&nbsp;'] as $text) {
            $this->assertNull(StoredOverrides::validText($text), $text);
        }
    }

    public function test_a_plain_ampersand_stays_allowed(): void
    {
        $this->assertSame('Speichern & schließen', StoredOverrides::validText('Speichern & schließen'));
        $this->assertSame('R&D', StoredOverrides::validText('R&D'));
        $this->assertSame('A & B; C', StoredOverrides::validText('A & B; C'));
    }

    public function test_markup_quotes_and_backticks_stay_refused(): void
    {
        foreach (['<i>x', 'x>', 'a "b"', 'a `b`', '   ', str_repeat('a', StoredOverrides::MAX_LENGTH + 1)] as $text) {
            $this->assertNull(StoredOverrides::validText($text), $text);
        }
        $this->assertSame('Speichern’s', StoredOverrides::validText("Speichern's"));
    }
}
