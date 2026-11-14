<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Usage;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Media\Usage\AssetReferenceSource;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;
use Preflow\Media\Usage\CopyReferences;
use Preflow\Media\Usage\CountsAssetUsage;
use Preflow\Media\Usage\LazyReferenceSource;
use Preflow\Media\Usage\PosterReferences;

/** AssetUsage::counts(): a page of assets at once, equal to count(of($id)) for each. */
#[RequiresPhpExtension('gd')]
final class AssetUsageCountsTest extends TestCase
{
    use MediaFixtures;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_batch_counts_equal_the_per_asset_usages_for_a_mixed_set(): void
    {
        $usage = new AssetUsage();
        $lib = null;
        $library = static function () use (&$lib): MediaLibrary {
            return $lib;
        };
        $usage->register(new LazyReferenceSource(PosterReferences::NAME, static fn (): PosterReferences => new PosterReferences($library)), last: true);
        $usage->register(new CopyReferences($library), last: true);
        $lib = $this->library(['bin_dir' => $this->assetRoot . '/bin', 'versions_dir' => $this->assetRoot . '/versions'], usage: $usage);

        $poster = $lib->upload($this->upload($this->png(40, 30), 'poster.png'), null);
        $original = $lib->upload($this->upload($this->png(40, 30), 'original.png'), null);
        $unused = $lib->upload($this->upload($this->png(40, 30), 'unused.png'), null);
        $clipA = $lib->upload($this->upload("a\n", 'a.txt'), null);
        $clipB = $lib->upload($this->upload("b\n", 'b.txt'), null);
        $lib->update($clipA->id, ['poster' => $poster->id]);
        $lib->update($clipB->id, ['poster' => $poster->id]);
        $lib->delete($clipB->id); // a binned asset's poster is still reported (without blocking)
        $lib->makeCopy($original->id, null);
        $binnedCopy = $lib->makeCopy($original->id, null);
        $lib->delete($binnedCopy->id); // a copy in the bin is not a usage
        // A site's own sources: one without a batch, one with.
        $usage->register(AssetUsageTest::source('pages', [$poster->id => [new AssetUsageEntry('Home', '/home', 'pages', type: 'page')]]));
        $usage->register($this->batchSource('flows', [$original->id => 2, $poster->id => 1]));

        $ids = [$poster->id, $original->id, $unused->id, $clipA->id, 'not-an-id'];
        $counts = $usage->counts($ids);

        $this->assertSame($ids, array_keys($counts), 'every given id, in order');
        foreach ($ids as $id) {
            $this->assertSame(count($usage->of($id)), $counts[$id], $id);
        }
        $this->assertSame([$poster->id => 4, $original->id => 3, $unused->id => 0, $clipA->id => 0, 'not-an-id' => 0], $counts);
        $this->assertSame([], $usage->counts([]));
    }

    public function test_a_batch_source_is_asked_once_and_others_per_asset(): void
    {
        $usage = new AssetUsage();
        $batch = $this->batchSource('flows', ['x' => 3]);
        $usage->register($batch);
        $plain = AssetUsageTest::source('pages', ['y' => [new AssetUsageEntry('Home', null, 'pages')]]);
        $usage->register($plain);

        $this->assertSame(['x' => 3, 'y' => 1, 'z' => 0], $usage->counts(['x', 'y', 'z', 'x']));
        $this->assertSame([['x', 'y', 'z']], $batch->calls);
    }

    public function test_lazy_source_passes_counts_to_its_source(): void
    {
        $batch = $this->batchSource('flows', ['x' => 2]);
        $lazy = new LazyReferenceSource('flows', static fn (): AssetReferenceSource => $batch);
        $plain = new LazyReferenceSource('pages', static fn (): AssetReferenceSource => AssetUsageTest::source('pages', ['y' => [new AssetUsageEntry('Home', null, 'pages')]]));

        $this->assertSame(['x' => 2], $lazy->usageCounts(['x', 'y']));
        $this->assertSame(['x' => 0, 'y' => 1], $plain->usageCounts(['x', 'y']));
    }

    public function test_the_entry_type_is_the_sources_key(): void
    {
        $usage = new AssetUsage();
        $lib = null;
        $usage->register(new CopyReferences(static function () use (&$lib): MediaLibrary {
            return $lib;
        }));
        $lib = $this->library(['versions_dir' => $this->assetRoot . '/versions'], usage: $usage);
        $original = $lib->upload($this->upload($this->png(40, 30), 'original.png'), null);
        $lib->makeCopy($original->id, null);

        $this->assertSame(['asset'], array_map(static fn (AssetUsageEntry $e): ?string => $e->type, $usage->of($original->id)));
        // A poster's user is another asset too, live or binned.
        $usage->register(new PosterReferences(static function () use (&$lib): MediaLibrary {
            return $lib;
        }), last: true);
        $poster = $lib->upload($this->upload($this->png(40, 30), 'poster.png'), null);
        $clip = $lib->upload($this->upload("a\n", 'a.txt'), null);
        $lib->update($clip->id, ['poster' => $poster->id]);
        $this->assertSame([['as poster', 'asset']], array_map(static fn (AssetUsageEntry $e): array => [$e->note, $e->type], $usage->of($poster->id)));
        $this->assertNull((new AssetUsageEntry('x', null, 's'))->type);
    }

    /** @param array<string, int> $counts */
    private function batchSource(string $name, array $counts): AssetReferenceSource&CountsAssetUsage
    {
        return new class($name, $counts) implements AssetReferenceSource, CountsAssetUsage {
            /** @var list<list<string>> */
            public array $calls = [];

            /** @param array<string, int> $counts */
            public function __construct(private readonly string $name, private readonly array $counts) {}

            public function name(): string
            {
                return $this->name;
            }

            public function usagesOf(string $assetId): array
            {
                return array_fill(0, $this->counts[$assetId] ?? 0, new AssetUsageEntry('Flow', null, $this->name));
            }

            public function usageCounts(array $assetIds): array
            {
                $this->calls[] = $assetIds;

                return array_intersect_key($this->counts, array_flip($assetIds));
            }
        };
    }
}
