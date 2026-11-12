<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Translations;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Translations\StoredOverrides;
use Preflow\I18n\OverrideRules;

/** Override texts are plain text: nothing a browser reads as markup or as an entity. */
final class StoredOverridesTextTest extends TestCase
{
    public function test_entity_shaped_text_is_refused(): void
    {
        foreach (['&#60;img src=x&#62;', 'a &#x3C; b', '&lt;b&gt;bold', 'Save &amp; close', 'x &QUOT; y', '&nbsp;'] as $text) {
            $this->assertNull(OverrideRules::validText($text), $text);
        }
    }

    public function test_a_plain_ampersand_stays_allowed(): void
    {
        $this->assertSame('Speichern & schließen', OverrideRules::validText('Speichern & schließen'));
        $this->assertSame('R&D', OverrideRules::validText('R&D'));
        $this->assertSame('A & B; C', OverrideRules::validText('A & B; C'));
    }

    public function test_markup_quotes_and_backticks_stay_refused(): void
    {
        foreach (['<i>x', 'x>', 'a "b"', 'a `b`', '   ', str_repeat('a', OverrideRules::MAX_LENGTH + 1)] as $text) {
            $this->assertNull(OverrideRules::validText($text), $text);
        }
        $this->assertSame('Speichern’s', OverrideRules::validText("Speichern's"));
    }

    public function test_compiled_texts_are_still_a_read_of_the_overrides(): void
    {
        $dir = sys_get_temp_dir() . '/ovr_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $dm = new \Preflow\Data\DataManager(['default' => new \Preflow\Data\Driver\JsonFileDriver($dir . '/store')]);
            $overrides = new StoredOverrides($dm, new \Preflow\Folio\Translations\OverridesCache($dir . '/cache'), new OverrideRules());
            $overrides->set('de', 'site.hello', 'Hallo', null);
            $this->assertSame('Hallo', $overrides->get('de', 'site.hello')); // compiles the file
            $log = new \Preflow\Folio\Tests\Support\ReadLog();
            $dm->observe($log);

            $this->assertSame('Hallo', $overrides->get('de', 'site.hello')); // from the compiled file

            $this->assertSame([$overrides->definition()->table], $log->take());
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
