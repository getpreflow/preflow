<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Field\AcceptsTranslatedTexts;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock\HeroBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\TradeoffNoteBlock;

final class BlocksTranslatedTextsTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const C = 'cccccccccccccccc';

    private Locales $locales;

    protected function setUp(): void
    {
        $this->locales = new Locales(['en', 'de', 'fr'], 'en');
    }

    private function type(): BlocksFieldType
    {
        $fields = new FieldTypeRegistry();
        $fields->register(new StringFieldType());
        $fields->register(new TextFieldType());
        $blocks = new BlocksFieldType(new BlockRegistry([HeroBlock::class, LinkBlock::class, TradeoffNoteBlock::class]), $fields);
        $fields->register($blocks);

        return $blocks;
    }

    public function test_sets_leaf_in_locale_keeps_others(): void
    {
        $type = $this->type();
        $this->assertInstanceOf(AcceptsTranslatedTexts::class, $type);
        $value = [
            ['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Hello', 'fr' => 'Bonjour'], 'intro' => ['en' => 'Intro']],
            ['_uid' => self::B, '_block' => 'link', 'label' => 'Read', 'url' => '/read'],
        ];

        $out = $type->withTranslatedTexts($value, [self::A . '.headline' => 'Hallo', self::B . '.label' => 'Lesen'], 'de', [], $this->locales);

        $this->assertSame(['en' => 'Hello', 'fr' => 'Bonjour', 'de' => 'Hallo'], $out[0]['headline']);
        $this->assertSame(['en' => 'Intro'], $out[0]['intro'], 'texts not given stay as they are');
        // A plain stored value counts as the default locale's text.
        $this->assertSame(['en' => 'Read', 'de' => 'Lesen'], $out[1]['label']);
        $this->assertSame('/read', $out[1]['url']);
        $this->assertSame([self::A, self::B], array_column($out, '_uid'), 'order and uids kept');
    }

    public function test_nested_blocks(): void
    {
        $value = [[
            '_uid' => self::A,
            '_block' => 'hero',
            'headline' => ['en' => 'Hello'],
            'tradeoff' => [['_uid' => self::C, '_block' => 'tradeoff_note', 'chose' => ['en' => 'Speed']]],
        ]];

        $out = $this->type()->withTranslatedTexts($value, [self::A . '.tradeoff.' . self::C . '.chose' => 'Tempo'], 'de', [], $this->locales);

        $this->assertSame(['en' => 'Speed', 'de' => 'Tempo'], $out[0]['tradeoff'][0]['chose']);
        $this->assertSame(['en' => 'Hello'], $out[0]['headline']);
    }

    public function test_unknown_path_ignored(): void
    {
        $value = [
            ['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Hello']],
            ['_uid' => self::B, '_block' => 'gone', 'headline' => ['en' => 'Kept']],
        ];

        $out = $this->type()->withTranslatedTexts($value, [
            self::C . '.headline' => 'no such block',
            self::A . '.nope' => 'no such field',
            self::B . '.headline' => 'unknown block type',
            self::B . '.label' => 'non-translatable field',
            self::A . '.tradeoff' => 'a blocks field, not a text',
            self::A . '.headline.deeper' => 'below a leaf',
        ], 'de', [], $this->locales);

        $this->assertSame($value, $out);
    }
}
