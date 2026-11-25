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

    public function test_discover_scans_app_blocks_and_round_trips_through_plain_data(): void
    {
        $registry = BlockRegistry::discover([], __DIR__ . '/Fixtures/app/app/Blocks', [ConfigCard::class]);
        $copy = BlockRegistry::fromArray($registry->toArray());

        $this->assertSame(['quote', 'config_card'], array_keys($registry->all()));
        $this->assertEquals($registry->all(), $copy->all());
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
