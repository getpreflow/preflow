<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockConfigException;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Tests\Fixtures\ConfigCard;
use Preflow\Blocks\Tests\Fixtures\DuplicateQuote;
use Preflow\Blocks\Tests\Fixtures\LegacyNamed;
use Preflow\Blocks\Tests\Fixtures\OptionsFromSelect;
use Preflow\Blocks\Tests\Fixtures\ReservedName;

final class BlockRegistryTest extends TestCase
{
    public function test_register_reads_the_attributes(): void
    {
        $card = (new BlockRegistry([ConfigCard::class, \stdClass::class, 'No\\Such\\Class']))->get('config_card');

        $this->assertNotNull($card);
        $this->assertSame('Card', $card->label);
        $this->assertSame('Blocks', $card->group, 'Block::DEFAULT_GROUP');
        $this->assertFalse($card->isComponent);
        $this->assertSame('Heading', $card->fields['heading']->label);
    }

    public function test_a_key_declared_twice_fails_naming_both_classes(): void
    {
        require_once __DIR__ . '/Fixtures/app/app/Blocks/Quote.php';

        $this->expectException(BlockConfigException::class);
        $this->expectExceptionMessage('Block key "quote" is declared by both App\Blocks\Quote and ' . DuplicateQuote::class . '.');
        new BlockRegistry([\App\Blocks\Quote::class, DuplicateQuote::class]);
    }

    public function test_field_names_starting_with_an_underscore_are_refused(): void
    {
        $this->expectException(BlockConfigException::class);
        $this->expectExceptionMessage('reserved name');
        new BlockRegistry([ReservedName::class]);
    }

    public function test_a_select_takes_its_options_from_another_field_of_the_block(): void
    {
        $block = (new BlockRegistry([OptionsFromSelect::class]))->get('options_from_select');

        $this->assertNotNull($block);
        $this->assertSame('choices', $block->fields['choice']->config['options_from']);
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function badOptionsFrom(): iterable
    {
        yield 'a name that is not a field of the block' => [\Preflow\Blocks\Tests\Fixtures\OptionsFromUnknown::class,
            'Block "options_from_unknown": field "choice": Select field option "options_from" names "choice_list", which is not a field of this block; name one of its fields (choices).'];
        yield 'the select itself' => [\Preflow\Blocks\Tests\Fixtures\OptionsFromItself::class,
            'Block "options_from_itself": field "choice": Select field option "options_from" must name another field of this block, not the select itself.'];
        yield 'both options and options_from' => [\Preflow\Blocks\Tests\Fixtures\OptionsFromWithOptions::class,
            'Block "options_from_with_options": field "choice": Select field has both "options" and "options_from"; keep one of them.'];
        yield 'display cards' => [\Preflow\Blocks\Tests\Fixtures\OptionsFromCards::class,
            'Block "options_from_cards": field "choice": Select field option "display": "cards" needs fixed "options" with descriptions; it cannot take "options_from". Use "select" or "radio".'];
        yield 'a blocks field as the source' => [\Preflow\Blocks\Tests\Fixtures\OptionsFromBlocksSource::class,
            'Block "options_from_blocks_source": field "choice": Select field option "options_from" names "items", a blocks field; name a field of this block that holds values (such as tags).'];
        yield 'the select as the only field' => [\Preflow\Blocks\Tests\Fixtures\OptionsFromOnlyField::class,
            'Block "options_from_only_field": field "choice": Select field option "options_from" names "choices", which is not a field of this block; the block has no other field: add one that holds the values (such as tags) and name it.'];
        yield 'a field that is not a select' => [\Preflow\Blocks\Tests\Fixtures\OptionsFromNotSelect::class,
            'Block "options_from_not_select": field "choice": "options_from" is an option of a select field; make the field type "select" or remove it.'];
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('badOptionsFrom')]
    public function test_a_wrong_options_from_fails_when_the_blocks_load_naming_the_block(string $class, string $message): void
    {
        $this->expectException(BlockConfigException::class);
        $this->expectExceptionMessage($message);
        new BlockRegistry([$class]);
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function badShowWhen(): iterable
    {
        yield 'a name that is not a field of the block' => [\Preflow\Blocks\Tests\Fixtures\ShowWhenUnknown::class,
            'Block "show_when_unknown": field "url": show_when names "style", which is not a field of the block. Name a field of the same block.'];
        yield 'the field itself' => [\Preflow\Blocks\Tests\Fixtures\ShowWhenItself::class,
            'Block "show_when_itself": field "url": show_when names the field itself. Name another field of the same block.'];
        yield 'a translatable source' => [\Preflow\Blocks\Tests\Fixtures\ShowWhenTranslatable::class,
            'Block "show_when_translatable": field "url": show_when names "kind", which is translatable. Name a field that is not translatable (one value in every language).'];
        yield 'a malformed rule' => [\Preflow\Blocks\Tests\Fixtures\ShowWhenMalformed::class,
            'Block "show_when_malformed": field "url": show_when {"field":"kind",">":3} has the operator ">", which show_when does not know; use one of =, !=, in, not_in, empty, filled.'];
        yield 'a cycle' => [\Preflow\Blocks\Tests\Fixtures\ShowWhenCycle::class,
            'Block "show_when_cycle": field "kind": show_when names "url", which depends on "kind" again (kind → url → kind). Break the cycle: let one of these fields show without depending on the others.'];
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('badShowWhen')]
    public function test_a_show_when_that_cannot_work_fails_when_the_blocks_load_naming_the_block(string $class, string $message): void
    {
        $this->expectException(BlockConfigException::class);
        $this->expectExceptionMessage($message);
        new BlockRegistry([$class]);
    }

    public function test_visible_block_reads_a_hidden_field_as_null_in_each_block_on_its_own(): void
    {
        $registry = new BlockRegistry([\Preflow\Blocks\Tests\Fixtures\ShowWhen\Offer::class, \Preflow\Blocks\Tests\Fixtures\ShowWhen\OfferLine::class]);
        $this->assertTrue($registry->hasShowWhen());

        $link = ['_uid' => 'o1', '_block' => 'offer', 'kind' => 'link', 'button_url' => '/a', 'lines' => [
            ['_uid' => 'l1', '_block' => 'offer_line', 'featured' => true, 'note' => 'Shown'],
            ['_uid' => 'l2', '_block' => 'offer_line', 'featured' => false, 'note' => 'Kept, not shown'],
        ]];
        $text = ['_uid' => 'o2', '_block' => 'offer', 'kind' => 'text', 'button_url' => '/b', 'lines' => []];

        $visible = $registry->visibleBlock($link);
        $this->assertSame('/a', $visible['button_url']);
        $this->assertSame('Shown', $visible['lines'][0]['note']);
        $this->assertNull($visible['lines'][1]['note'], 'a nested block decides by its own source');
        $this->assertNull($registry->visibleBlock($text)['button_url']);
        $this->assertSame(['button_url'], $registry->hiddenFieldsOf('offer', $text));

        // A record's blocks fields (folio_get, templates reading blocks directly).
        $fields = ['body' => new \Preflow\Data\TypeFieldDefinition('body', 'blocks'), 'title' => new \Preflow\Data\TypeFieldDefinition('title')];
        $values = $registry->visibleBlocksIn(['title' => 'T', 'body' => [$link, $text]], $fields);
        $this->assertSame('/a', $values['body'][0]['button_url']);
        $this->assertNull($values['body'][1]['button_url']);
        $this->assertSame('T', $values['title']);
    }

    public function test_blocks_without_show_when_come_back_unchanged(): void
    {
        $registry = new BlockRegistry([ConfigCard::class]);
        $block = ['_uid' => 'c1', '_block' => 'config_card', 'heading' => 'H'];

        $this->assertFalse($registry->hasShowWhen());
        $this->assertSame($block, $registry->visibleBlock($block));
        $this->assertSame([], $registry->hiddenFieldsOf('config_card', $block));
        $this->assertSame(['body' => [$block]], $registry->visibleBlocksIn(['body' => [$block]], ['body' => new \Preflow\Data\TypeFieldDefinition('body', 'blocks')]));
    }

    public function test_discover_scans_app_blocks_and_round_trips_through_plain_data(): void
    {
        $registry = BlockRegistry::discover([], __DIR__ . '/Fixtures/app/app/Blocks', [ConfigCard::class]);
        $copy = BlockRegistry::fromArray($registry->toArray());

        $this->assertSame(['quote', 'config_card'], array_keys($registry->all()));
        $this->assertEquals($registry->all(), $copy->all());
    }

    public function test_a_package_s_field_check_runs_when_the_registry_is_built_naming_the_block(): void
    {
        $seen = [];
        $check = static function (array $fields) use (&$seen): void {
            $seen[] = array_keys($fields);
        };
        $registry = new BlockRegistry([ConfigCard::class], [$check]);
        $this->assertSame([['heading', 'style']], $seen, 'once per block, with its field definitions');
        $this->assertInstanceOf(\Preflow\Data\TypeFieldDefinition::class, $registry->get('config_card')?->fields['style']);

        $refuse = static function (array $fields): void {
            throw new \LogicException('field "style": "palette" lists "red"; write each colour as #rrggbb.');
        };
        try {
            new BlockRegistry([ConfigCard::class], [$refuse]);
            $this->fail('the check did not stop the build');
        } catch (BlockConfigException $e) {
            $this->assertSame('Block "config_card": field "style": "palette" lists "red"; write each colour as #rrggbb.', $e->getMessage());
        }
    }

    public function test_a_field_check_runs_once_not_for_a_cached_or_manifest_registry(): void
    {
        $cache = sys_get_temp_dir() . '/blocks_checks_' . bin2hex(random_bytes(4)) . '/blocks.cache';
        $runs = 0;
        $check = static function (array $fields) use (&$runs): void {
            ++$runs;
        };
        try {
            $built = BlockRegistry::discover([], __DIR__ . '/Fixtures/missing', [ConfigCard::class], $cache, useCache: true, checks: [$check]);
            $this->assertSame(1, $runs, 'run when the registry is built');
            BlockRegistry::discover([], __DIR__ . '/Fixtures/missing', [ConfigCard::class], $cache, useCache: true, checks: [$check]);
            BlockRegistry::fromArray($built->toArray());
            $this->assertSame(1, $runs, 'a registry read from blocks.cache or the boot manifest was checked when that was written');
        } finally {
            @unlink($cache);
            @rmdir(dirname($cache));
        }
    }

    public function test_attributes_under_an_alias_count(): void
    {
        if (!class_exists('Legacy\\Blocks\\Block', false)) {
            class_alias(\Preflow\Blocks\Block::class, 'Legacy\\Blocks\\Block');
            class_alias(\Preflow\Blocks\BlockField::class, 'Legacy\\Blocks\\BlockField');
        }

        $legacy = (new BlockRegistry([LegacyNamed::class]))->get('legacy');

        $this->assertNotNull($legacy);
        $this->assertSame('text', $legacy->fields['body']->type);
    }
}
