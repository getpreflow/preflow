<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Cleanup;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\Cleanup\AssetCleanup;
use Preflow\Media\Cleanup\CleanupCandidate;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Media\Tests\Usage\AssetUsageTest;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;
use Preflow\Media\Usage\CopyReferences;
use Preflow\Media\Usage\PosterReferences;

/** Which assets cleanup lists: unused (no source reports them), old enough, live, and the filters. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class AssetCleanupTest extends TestCase
{
    use MediaFixtures;

    private MediaLibrary $lib;
    private AssetUsage $usage;
    private AssetCleanup $cleanup;
    private \DateTimeImmutable $clock;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->clock = new \DateTimeImmutable('2026-10-01T10:00:00+00:00');
        $this->usage = new AssetUsage();
        $this->lib = $lib = $this->library(
            [
                'versions_dir' => $this->assetRoot . '/versions',
                'private_dir' => $this->assetRoot . '/private',
                'bin_dir' => $this->assetRoot . '/bin',
            ],
            new VariantGenerator(new GdBackend(), [480], 75),
            $this->usage,
            fn (): \DateTimeImmutable => $this->clock,
        );
        $this->usage->register(new PosterReferences(static fn (): MediaLibrary => $lib), last: true);
        $this->usage->register(new CopyReferences(static fn (): MediaLibrary => $lib), last: true);
        $this->cleanup = new AssetCleanup($this->lib, $this->usage, ['enabled' => true, 'min_age_days' => 7, 'bin_days' => 30], fn (): \DateTimeImmutable => $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function image(string $name = 'photo.jpg', ?string $by = 'ed', bool $private = false): Asset
    {
        $image = imagecreatetruecolor(60, 40);
        ob_start();
        imagejpeg($image);

        return $this->lib->upload($this->upload((string) ob_get_clean(), $name), $by, $private);
    }

    private function text(string $name = 'notes.txt', ?string $by = 'ed'): Asset
    {
        return $this->lib->upload($this->upload("notes\n", $name), $by);
    }

    /** The smallest file fileinfo reads as video/mp4 (an ftyp box). */
    private function mp4(string $name = 'clip.mp4'): Asset
    {
        $bytes = pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isomiso2' . pack('N', 8) . 'free';

        return $this->lib->upload($this->upload($bytes, $name), 'ed');
    }

    private function later(int $days): void
    {
        $this->clock = $this->clock->modify("+{$days} days");
    }

    /**
     * @param list<CleanupCandidate> $candidates
     * @return list<string> their asset ids, sorted
     */
    private static function ids(array $candidates): array
    {
        $ids = array_map(static fn (CleanupCandidate $c): string => $c->asset->id, $candidates);
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private static function sorted(string ...$ids): array
    {
        sort($ids);

        return $ids;
    }

    public function test_is_enabled_follows_the_setting(): void
    {
        $this->assertTrue($this->cleanup->isEnabled());
        $this->assertFalse((new AssetCleanup($this->lib, $this->usage))->isEnabled(), 'off by default');
    }

    public function test_unused_old_asset_is_candidate(): void
    {
        $image = $this->image();
        $pdf = $this->lib->importFile(dirname(__DIR__) . '/Fixtures/pdf/rgb.pdf', 'leaflet.pdf', 'ed');
        $video = $this->mp4();
        $text = $this->text();
        $this->later(8);

        $candidates = $this->cleanup->candidates();

        $this->assertSame(['image', 'document', 'video', 'document'], [$image->kind(), $pdf->kind(), $video->kind(), $text->kind()]);
        $this->assertSame(self::sorted($image->id, $pdf->id, $video->id, $text->id), self::ids($candidates));
        $this->assertSame('no source reports it (asked: media, copies)', $candidates[0]->reason);
        $this->assertSame(4, $this->cleanup->selected());
    }

    public function test_used_asset_is_not_candidate(): void
    {
        $used = $this->image('used.jpg');
        $unused = $this->image('unused.jpg');
        $this->usage->register(AssetUsageTest::source('pages', [$used->id => [new AssetUsageEntry('Home page', '/admin/page/1', 'pages')]]));
        $this->later(8);

        $candidates = $this->cleanup->candidates();

        $this->assertSame([$unused->id], self::ids($candidates));
        $this->assertSame('no source reports it (asked: pages, media, copies)', $candidates[0]->reason);
    }

    public function test_non_blocking_usage_also_protects(): void
    {
        $asset = $this->image();
        $this->usage->register(AssetUsageTest::source('revisions', [$asset->id => [
            new AssetUsageEntry('Home page', null, 'revisions', 'earlier revision', blocksDelete: false),
        ]]));
        $this->later(8);

        $this->assertSame([], $this->cleanup->candidates());
    }

    public function test_recent_upload_is_not_candidate(): void
    {
        $asset = $this->image();
        $this->later(6);

        $this->assertSame([], $this->cleanup->candidates());
        $this->assertSame(0, $this->cleanup->selected());

        $this->later(2);
        $this->assertSame([$asset->id], self::ids($this->cleanup->candidates()));
    }

    public function test_original_of_live_copy_is_not_candidate(): void
    {
        $original = $this->image('original.jpg');
        $copy = $this->lib->makeCopy($original->id, 'ed');
        $this->later(8);

        $this->assertSame([$copy->id], self::ids($this->cleanup->candidates()));

        $this->lib->delete($copy->id); // a binned copy no longer keeps its original
        $this->assertSame([$original->id], self::ids($this->cleanup->candidates()));
    }

    public function test_poster_is_not_candidate(): void
    {
        $poster = $this->image('poster.jpg');
        $clip = $this->mp4();
        $this->lib->update($clip->id, ['poster' => $poster->id]);
        $this->later(8);

        $this->assertSame([$clip->id], self::ids($this->cleanup->candidates()));

        $this->lib->delete($clip->id); // the clip can come back from the bin: its poster stays
        $this->assertSame([], $this->cleanup->candidates());
    }

    public function test_filters_by_kind_by_and_private(): void
    {
        $edImage = $this->image('ed.jpg', 'ed');
        $annPrivate = $this->image('ann.jpg', 'ann', private: true);
        $edText = $this->text('ed.txt', 'ed');
        $this->later(8);

        $this->assertSame(self::sorted($edImage->id, $annPrivate->id), self::ids($this->cleanup->candidates(['kind' => 'image'])));
        $this->assertSame([$edText->id], self::ids($this->cleanup->candidates(['kind' => 'document'])));
        $this->assertSame([], $this->cleanup->candidates(['kind' => 'video']));
        $this->assertSame(self::sorted($edImage->id, $edText->id), self::ids($this->cleanup->candidates(['by' => 'ed'])));
        $this->assertSame([$annPrivate->id], self::ids($this->cleanup->candidates(['private' => true])));
        $this->assertSame(self::sorted($edImage->id, $edText->id), self::ids($this->cleanup->candidates(['private' => false])));
        $this->assertSame([$edImage->id], self::ids($this->cleanup->candidates(['kind' => 'image', 'by' => 'ed', 'private' => false])));
        $this->assertSame([], $this->cleanup->candidates(['older_than_days' => 30]));
        $this->assertSame(1, $this->cleanup->selected(['private' => true]));

        $this->expectException(\InvalidArgumentException::class);
        $this->cleanup->candidates(['kind' => 'spreadsheet']);
    }

    public function test_older_than_days_replaces_the_minimum_age(): void
    {
        $asset = $this->image();
        $this->later(2);

        $this->assertSame([], $this->cleanup->candidates());
        $this->assertSame([$asset->id], self::ids($this->cleanup->candidates(['older_than_days' => 1])));
    }

    public function test_binned_assets_are_not_candidates(): void
    {
        $binned = $this->image('binned.jpg');
        $live = $this->image('live.jpg');
        $this->lib->delete($binned->id);
        $this->later(8);

        $this->assertSame([$live->id], self::ids($this->cleanup->candidates()));
    }

    public function test_candidates_in_chunks_oldest_first(): void
    {
        $first = $this->image('first.jpg');
        $this->later(1);
        $used = $this->image('used.jpg');
        $this->usage->register(AssetUsageTest::source('pages', [$used->id => [new AssetUsageEntry('Home page', null, 'pages')]]));
        $this->later(1);
        $third = $this->image('third.jpg');
        $this->later(8);

        $this->assertSame(3, $this->cleanup->selected());
        $this->assertSame([$first->id], self::ids($this->cleanup->candidates(limit: 2)), 'the two oldest are checked; one is used');
        $this->assertSame([$third->id], self::ids($this->cleanup->candidates(offset: 2, limit: 2)));
    }
}
