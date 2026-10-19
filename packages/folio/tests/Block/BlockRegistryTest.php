<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Block;

use PHPUnit\Framework\TestCase;
use Preflow\Components\ComponentMap;
use Preflow\Core\Application;
use Preflow\Folio\Block\BlockConfigException;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Tests\Fixtures\Blocks\Duplicate\DuplicateHero;
use Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock\HeroBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\TradeoffNoteBlock;

final class BlockRegistryTest extends TestCase
{
    private function registry(array $classes = [HeroBlock::class, LinkBlock::class, TradeoffNoteBlock::class]): BlockRegistry
    {
        return new BlockRegistry($classes);
    }

    public function test_discovers_component_and_data_only_blocks(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->get('hero')->isComponent);
        $this->assertFalse($registry->get('link')->isComponent);
        $this->assertSame('Hero', $registry->get('hero')->label);
        $this->assertSame('Sections', $registry->get('hero')->group);
        $this->assertSame('block', $registry->get('hero')->icon);
        $this->assertTrue($registry->get('hero')->fields['headline']->translatable);
        $this->assertSame(['required'], $registry->get('hero')->fields['headline']->validate);
        $this->assertNull($registry->get('nope'));
    }

    public function test_duplicate_key_is_a_boot_error(): void
    {
        $this->expectException(BlockConfigException::class);
        $this->expectExceptionMessageMatches('/"hero".*HeroBlock.*DuplicateHero/');
        $this->registry([HeroBlock::class, DuplicateHero::class]);
    }

    public function test_blocks_field_config_carries_allowed_min_max(): void
    {
        $this->assertSame(['allowed' => ['tradeoff_note'], 'max' => 1], $this->registry()->get('hero')->fields['tradeoff']->config);
    }

    public function test_discover_combines_component_map_and_explicit_classes(): void
    {
        $registry = BlockRegistry::discover(
            new ComponentMap(['HeroBlock' => HeroBlock::class, 'NotABlock' => \stdClass::class]),
            sys_get_temp_dir() . '/no-such-blocks-dir',
            [LinkBlock::class],
        );

        $this->assertSame(['hero', 'link'], array_keys($registry->all()));
    }

    public function test_cache_is_used_when_enabled(): void
    {
        $cache = sys_get_temp_dir() . '/folio_blocks_' . bin2hex(random_bytes(4)) . '.php';
        BlockRegistry::discover(new ComponentMap([]), '/nowhere', [HeroBlock::class, LinkBlock::class], $cache, useCache: true);

        $again = BlockRegistry::discover(new ComponentMap([]), '/nowhere', [], $cache, useCache: true);
        @unlink($cache);

        $this->assertSame(['hero', 'link'], array_keys($again->all()));
        $this->assertTrue($again->get('hero')->fields['headline']->translatable);
    }

    public function test_grouped_respects_allowed(): void
    {
        $groups = $this->registry()->grouped(['link', 'hero']);

        $this->assertSame(['Sections', 'Blocks'], array_keys($groups));
        $this->assertSame('hero', $groups['Sections'][0]->key);
        $this->assertSame('link', $groups['Blocks'][0]->key);
    }

    public function test_block_component_hydrates_fields_from_props(): void
    {
        $hero = new HeroBlock();
        $hero->setProps(['headline' => 'H', '_uid' => 'u1', 'unrelated' => 'x', 'tradeoff' => [['chose' => 'c']]]);

        $this->assertSame('H', $hero->headline);
        $this->assertSame('u1', $hero->_uid);
        $this->assertSame([['chose' => 'c']], $hero->tradeoff);
        $this->assertFalse(property_exists($hero, 'unrelated'));
    }

    public function test_component_map_is_bound(): void
    {
        $dir = sys_get_temp_dir() . '/cmap_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");

        $app = Application::create($dir);
        $app->boot();

        $this->assertInstanceOf(ComponentMap::class, $app->container()->get(ComponentMap::class));
        @unlink($dir . '/config/app.php');
        @rmdir($dir . '/config');
        @rmdir($dir);
    }
}
