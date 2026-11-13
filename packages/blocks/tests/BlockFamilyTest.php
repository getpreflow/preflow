<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockDefinition;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Tests\Fixtures\ConfigCard;
use Preflow\Blocks\Tests\Fixtures\GalleryBlock;
use Preflow\Data\TypeFamily;

/** #[Block(family: …)]: on the definition, through the boot manifest's plain data and the discovery cache. */
final class BlockFamilyTest extends TestCase
{
    private ?string $cache = null;

    protected function tearDown(): void
    {
        if ($this->cache !== null) {
            @unlink($this->cache);
        }
    }

    public function test_a_block_declares_its_family_and_defaults_to_none(): void
    {
        $registry = new BlockRegistry([GalleryBlock::class, ConfigCard::class]);

        $this->assertSame(TypeFamily::Asset, $registry->get('gallery')?->family);
        $this->assertNull($registry->get('config_card')?->family);
    }

    public function test_plain_data_holds_the_value_and_reads_it_back(): void
    {
        $registry = new BlockRegistry([GalleryBlock::class, ConfigCard::class]);

        $data = $registry->toArray();
        $this->assertSame(['asset', null], array_column($data, 'family'));
        $this->assertEquals($registry->all(), BlockRegistry::fromArray($data)->all());
    }

    public function test_plain_data_from_before_families_reads_as_none(): void
    {
        $data = (new BlockRegistry([GalleryBlock::class]))->toArray();
        unset($data[0]['family']);

        $this->assertNull(BlockRegistry::fromArray($data)->get('gallery')?->family);
    }

    public function test_the_discovery_cache_keeps_the_family(): void
    {
        $this->cache = sys_get_temp_dir() . '/blocks_family_' . bin2hex(random_bytes(4)) . '.cache';

        BlockRegistry::discover([], '/nonexistent', [GalleryBlock::class], $this->cache, true);
        $this->assertFileExists($this->cache);
        $cached = BlockRegistry::discover([], '/nonexistent', [GalleryBlock::class], $this->cache, true);

        $this->assertInstanceOf(BlockDefinition::class, $cached->get('gallery'));
        $this->assertSame(TypeFamily::Asset, $cached->get('gallery')->family);
    }

    public function test_a_cache_written_before_families_is_rebuilt(): void
    {
        $this->cache = sys_get_temp_dir() . '/blocks_family_' . bin2hex(random_bytes(4)) . '.cache';
        $file = (new \ReflectionClass(GalleryBlock::class))->getFileName();
        // What 0.74 wrote: the signature without the definition's shape.
        $old = hash('xxh3', implode('|', [GalleryBlock::class . '@' . filemtime((string) $file)]));
        file_put_contents($this->cache, serialize(['signature' => $old, 'blocks' => []]));

        $registry = BlockRegistry::discover([], '/nonexistent', [GalleryBlock::class], $this->cache, true);

        $this->assertSame(TypeFamily::Asset, $registry->get('gallery')?->family, 'the stale cache (no blocks) was not used');
    }
}
