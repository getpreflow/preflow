<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockConfigException;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Tests\Fixtures\ConfigCard;
use Preflow\Blocks\Tests\Fixtures\DuplicateQuote;
use Preflow\Blocks\Tests\Fixtures\LegacyNamed;
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
