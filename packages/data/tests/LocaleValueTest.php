<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;

final class LocaleValueTest extends TestCase
{
    private Locales $locales;

    protected function setUp(): void
    {
        $this->locales = new Locales(['en', 'de', 'fr'], 'en', 'de');
    }

    public function test_map_read_tolerance(): void
    {
        $this->assertSame(['en' => 'About'], LocaleValue::map('About', $this->locales));
        $this->assertSame(['en' => 'A', 'de' => 'B'], LocaleValue::map('{"en":"A","de":"B"}', $this->locales));
        $this->assertSame(['en' => 'A'], LocaleValue::map(['en' => 'A'], $this->locales));
        $this->assertSame([], LocaleValue::map(null, $this->locales));
        $this->assertSame(['en' => '[1,2]'], LocaleValue::map('[1,2]', $this->locales)); // JSON, but not an object
    }

    public function test_resolve_follows_chain_and_reports_fallback(): void
    {
        $map = ['en' => 'E', 'de' => 'D'];

        $this->assertSame(['D', true], LocaleValue::resolve($map, $this->locales, 'fr'));
        $this->assertSame(['D', false], LocaleValue::resolve($map, $this->locales, 'de'));
        $this->assertSame(['E', true], LocaleValue::resolve(['en' => 'E', 'de' => ''], $this->locales, 'de'));
        $this->assertSame([null, true], LocaleValue::resolve([], $this->locales, 'de'));
    }

    public function test_pick_is_exact(): void
    {
        $this->assertNull(LocaleValue::pick(['en' => 'E'], $this->locales, 'de'));
        $this->assertSame('Plain', LocaleValue::pick('Plain', $this->locales, 'en'));
        $this->assertNull(LocaleValue::pick('Plain', $this->locales, 'de'));
    }

    public function test_default_reads_map_for_shared_fields(): void
    {
        $this->assertSame('E', LocaleValue::default(['en' => 'E', 'de' => 'D'], $this->locales));
        $this->assertSame('Plain', LocaleValue::default('Plain', $this->locales));
        $this->assertSame(['a', 'b'], LocaleValue::default(['a', 'b'], $this->locales)); // a list is not a locale map
    }

    public function test_resolve_tree_nested(): void
    {
        $tree = [
            '_uid' => 'u',
            'headline' => ['en' => 'H', 'de' => 'Ü'],
            'items' => [['label' => ['en' => 'L']]],
        ];

        $resolved = LocaleValue::resolveTree($tree, $this->locales, 'de');

        $this->assertSame('u', $resolved['_uid']);
        $this->assertSame('Ü', $resolved['headline']);
        $this->assertSame('L', $resolved['items'][0]['label']);
    }

    public function test_chain_is_unique_and_limited_to_available(): void
    {
        $this->assertSame(['fr', 'de', 'en'], $this->locales->chain('fr'));
        $this->assertSame(['en', 'de'], $this->locales->chain('en'));
        $this->assertSame(['de', 'en'], $this->locales->chain('xx'));
    }

    public function test_single_locale_is_not_multilingual(): void
    {
        $this->assertFalse(Locales::single('en')->isMultilingual());
        $this->assertTrue($this->locales->isMultilingual());
        $this->assertTrue($this->locales->has('de'));
        $this->assertFalse($this->locales->has('xx'));
    }

    public function test_default_decodes_stored_json_map_on_sql(): void
    {
        // SQL drivers return locale maps as JSON text.
        $this->assertSame('x', LocaleValue::default('{"en":"x","de":"y"}', $this->locales));
        $this->assertSame('{"foo":1}', LocaleValue::default('{"foo":1}', $this->locales));
    }

    public function test_map_only_accepts_locale_shaped_objects(): void
    {
        // A legacy plain value that merely looks like JSON stays the default value.
        $this->assertSame(['en' => '{"foo":1}'], LocaleValue::map('{"foo":1}', $this->locales));
        $this->assertSame(['en' => ['foo' => 1]], LocaleValue::map(['foo' => 1], $this->locales));
        // A locale that was later removed from config still reads as a map (no data loss).
        $this->assertSame(['en' => 'E', 'it' => 'I'], LocaleValue::map(['en' => 'E', 'it' => 'I'], $this->locales));
    }
}
