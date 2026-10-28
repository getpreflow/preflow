<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\ImageTag;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Preview\PreviewRenderer;
use Preflow\Media\Preview\PreviewRenderers;
use Preflow\Media\Tests\Support\FixedLocale;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Media\Tests\Usage\AssetUsageTest;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;
use Preflow\Media\Usage\CopyReferences;
use Preflow\Media\Usage\PosterReferences;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

/** Every delete goes to the bin (bin_dir): delete, restoreFromBin(), emptyBin(), and what a binned asset no longer does. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class BinTest extends TestCase
{
    use MediaFixtures;

    private MediaLibrary $lib;
    private AssetUsage $usage;
    private \DateTimeImmutable $clock;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->clock = new \DateTimeImmutable('2026-10-01T10:00:00+00:00');
        $this->usage = new AssetUsage();
        $this->lib = $this->lib();
        $lib = $this->lib;
        $this->usage->register(new CopyReferences(static fn (): MediaLibrary => $lib), last: true);
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    /** @param array<string, mixed> $config */
    private function lib(array $config = [], bool $withUsage = true): MediaLibrary
    {
        return $this->library(
            $config + [
                'versions_dir' => $this->assetRoot . '/versions',
                'private_dir' => $this->assetRoot . '/private',
                'bin_dir' => $this->assetRoot . '/bin',
            ],
            new VariantGenerator(new GdBackend(), [480], 75),
            $withUsage ? $this->usage : null,
            fn (): \DateTimeImmutable => $this->clock,
        );
    }

    private function jpeg(int $width = 600, int $height = 400, int $red = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, 80, 40));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function photo(string $name = 'photo.jpg'): Asset
    {
        return $this->lib->upload($this->upload($this->jpeg(), $name), 'ed');
    }

    private function later(int $days): void
    {
        $this->clock = $this->clock->modify("+{$days} days");
    }

    /** @return list<string> every file under the asset's bin folder, relative to it */
    private function binFiles(string $id): array
    {
        $dir = $this->assetRoot . '/bin/' . $id;
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $files[] = substr($f->getPathname(), strlen($dir) + 1);
        }
        sort($files);

        return $files;
    }

    /** @return list<string> the files of the asset's id in the public media dir */
    private function publicFiles(Asset $asset): array
    {
        return glob($this->assetRoot . '/media/' . dirname($asset->path) . '/' . $asset->id . '*') ?: [];
    }

    public function test_delete_moves_to_bin(): void
    {
        $asset = $this->photo();
        $asset = $this->lib->replace($asset->id, $this->upload($this->jpeg(red: 20), 'second.jpg'), 'ed');
        $this->assertNotSame([], $asset->variants);
        $this->assertNotSame([], $this->publicFiles($asset));

        $this->lib->delete($asset->id);

        $this->assertSame([], $this->publicFiles($asset), 'the file and its variants left the public dir');
        $this->assertDirectoryDoesNotExist($this->assetRoot . '/versions/' . $asset->id);
        $this->assertSame(['files/' . basename($asset->path), 'versions/v1.jpg'], $this->binFiles($asset->id));
        $this->assertNull($this->lib->find($asset->id));
        $binned = $this->lib->findBinned($asset->id);
        $this->assertNotNull($binned);
        $this->assertSame('2026-10-01T10:00:00+00:00', $binned->binnedAt);
        $this->assertSame([], $binned->variants);
        $this->assertCount(1, $binned->versions);
        $this->assertNull($this->lib->findBinned($this->photo('live.jpg')->id), 'a live asset is not binned');
    }

    public function test_binned_asset_does_not_resolve(): void
    {
        $asset = $this->photo();
        $tag = new ImageTag($this->lib, new FixedLocale('en'), [], new AssetCollector(new NonceGenerator()), '', ImageWidths::fromConfig([], [480]));
        $this->assertNotSame('', $tag->render($asset->id));

        $this->lib->delete($asset->id);

        $this->assertSame('', $tag->render($asset->id));
        $this->assertSame('', $tag->url($asset->id));
        $this->assertSame(0, $this->lib->query()->total);
        $this->assertSame(0, $this->lib->query(search: 'photo')->total);
        $this->assertSame([], $this->lib->all());
        $this->assertFalse($this->lib->isAssetId($asset->id));
        $page = $this->lib->binned();
        $this->assertSame(1, $page->total);
        $this->assertSame($asset->id, $page->items[0]->id);
    }

    public function test_binned_lists_newest_binned_first(): void
    {
        $a = $this->photo('a.jpg');
        $b = $this->photo('b.jpg');
        $this->lib->delete($b->id);
        $this->later(1);
        $this->lib->delete($a->id);

        $page = $this->lib->binned(1, 1);

        $this->assertSame(2, $page->total);
        $this->assertSame([$a->id], array_map(static fn (Asset $x) => $x->id, $page->items));
        $this->assertSame([$b->id], array_map(static fn (Asset $x) => $x->id, $this->lib->binned(2, 1)->items));
    }

    public function test_restore_brings_back_file_and_variants(): void
    {
        $asset = $this->photo();
        $asset = $this->lib->replace($asset->id, $this->upload($this->jpeg(red: 20), 'second.jpg'), 'ed');
        $bytes = (string) file_get_contents((string) $this->lib->file($asset));
        $this->lib->delete($asset->id);

        $restored = $this->lib->restoreFromBin($asset->id);

        $this->assertNull($restored->binnedAt);
        $this->assertEquals($restored, $this->lib->find($asset->id));
        $this->assertSame($bytes, (string) file_get_contents((string) $this->lib->file($restored)));
        $this->assertNotSame([], $restored->variants);
        foreach ($restored->variants as $v) {
            $this->assertFileExists($this->lib->absolute($v->path));
        }
        $this->assertNotNull($this->lib->versionFile($asset->id, 1));
        $this->assertSame($asset->color, $restored->color);
        $this->assertDirectoryDoesNotExist($this->assetRoot . '/bin/' . $asset->id);
        $this->assertNull($this->lib->findBinned($asset->id));
        $this->assertSame(1, $this->lib->query()->total);
    }

    public function test_restore_renders_an_edited_image_again(): void
    {
        $asset = $this->lib->rotate($this->photo()->id, 1);
        $this->assertNotNull($asset->master);
        $this->lib->delete($asset->id);
        $this->assertFileDoesNotExist($this->lib->absolute($asset->master->path));

        $restored = $this->lib->restoreFromBin($asset->id);

        $this->assertSame(1, $restored->rotation);
        $this->assertNotNull($restored->master);
        $this->assertFileExists($this->lib->absolute($restored->master->path));
        $this->assertStringContainsString('-edit-', $restored->variants[0]->path ?? '');
    }

    public function test_private_asset_bins_from_and_restores_to_the_private_dir(): void
    {
        $asset = $this->lib->upload($this->upload($this->jpeg(), 'secret.jpg'), 'ed', true);
        $file = $this->assetRoot . '/private/' . $asset->path;
        $this->assertFileExists($file);

        $this->lib->delete($asset->id);
        $this->assertFileDoesNotExist($file);
        $this->assertSame(['files/' . basename($asset->path)], $this->binFiles($asset->id));

        $restored = $this->lib->restoreFromBin($asset->id);
        $this->assertFileExists($file);
        $this->assertTrue($restored->private);
        $this->assertSame([], glob($this->assetRoot . '/media/*/*/' . $asset->id . '*') ?: []);
    }

    public function test_document_preview_goes_to_the_bin_and_back(): void
    {
        $renderers = new PreviewRenderers();
        $renderers->register(new class implements PreviewRenderer {
            public function supports(string $mime): bool
            {
                return $mime === 'text/plain';
            }

            public function render(string $file, int $longSide): ?string
            {
                $tmp = tempnam(sys_get_temp_dir(), 'preview');
                imagepng(imagecreatetruecolor(600, 800), $tmp);

                return $tmp;
            }
        });
        $lib = new MediaLibrary(
            new \Preflow\Data\DataManager(drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json'),
            new \Preflow\Data\Locales(['en'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            ['versions_dir' => $this->assetRoot . '/versions', 'bin_dir' => $this->assetRoot . '/bin'],
            new VariantGenerator(new GdBackend(), [480], 75),
            previews: $renderers,
        );
        $doc = $lib->upload($this->upload("Some notes.\n", 'notes.txt'), null);
        $this->assertNotNull($doc->preview);
        $this->assertNotSame([], $doc->variants);

        $lib->delete($doc->id);

        $this->assertSame(['files/' . basename($doc->preview->path), 'files/' . basename($doc->path)], $this->binFiles($doc->id));
        $this->assertSame([], $this->publicFiles($doc), 'the preview variants are gone');

        $restored = $lib->restoreFromBin($doc->id);

        $this->assertFileExists($lib->absolute($doc->path));
        $this->assertFileExists($lib->absolute($doc->preview->path));
        $this->assertSame($doc->preview->path, $restored->preview?->path);
        $this->assertNotSame([], $restored->variants);
        foreach ($restored->variants as $v) {
            $this->assertFileExists($lib->absolute($v->path));
        }
    }

    public function test_restore_of_an_unknown_or_live_asset_is_refused(): void
    {
        $asset = $this->photo();

        $this->expectException(\InvalidArgumentException::class);
        $this->lib->restoreFromBin($asset->id);
    }

    public function test_restore_copy_restores_binned_original(): void
    {
        $original = $this->photo();
        $copy = $this->lib->makeCopy($original->id, 'ed');
        $this->lib->delete($copy->id);
        $this->lib->delete($original->id);

        $restored = $this->lib->restoreFromBin($copy->id);

        $this->assertSame($original->id, $restored->derivedFrom);
        $this->assertNotNull($this->lib->find($original->id));
        $this->assertFileExists((string) $this->lib->file($this->lib->find($original->id)));
        $this->assertSame([$copy->id], array_map(static fn (Asset $c) => $c->id, $this->lib->copies($original->id)));
    }

    public function test_restore_copy_of_gone_original_clears_link(): void
    {
        $original = $this->photo();
        $copy = $this->lib->makeCopy($original->id, 'ed');
        $this->assertSame(MediaLibrary::MAKER_COPY, $copy->maker);
        $this->lib->delete($copy->id);
        $this->lib->discard($original->id); // gone for good

        $restored = $this->lib->restoreFromBin($copy->id);

        $this->assertFalse($restored->isCopy());
        $this->assertNull($restored->derivedFrom);
        $this->assertNull($restored->maker);
        $this->assertSame([], $restored->makerParams);
        $this->assertNull($restored->derivedVersion);
        $this->assertSame(1, $this->lib->query()->total, 'an ordinary asset now, listed in the library');
    }

    public function test_binned_copy_does_not_block_binning_original(): void
    {
        $original = $this->photo();
        $copy = $this->lib->makeCopy($original->id, 'ed');
        try {
            $this->lib->delete($original->id);
            $this->fail('an original with a live copy is in use');
        } catch (AssetInUseException $e) {
            $this->assertSame(CopyReferences::NAME, $e->usages[0]->source);
        }

        $this->lib->delete($copy->id);

        $this->assertSame([], $this->lib->copies($original->id));
        $this->assertSame([$original->id => 0], $this->lib->copyCounts([$original->id]));
        $this->assertSame([], $this->usage->of($original->id));
        $this->lib->delete($original->id);
        $this->assertNotNull($this->lib->findBinned($original->id));
    }

    public function test_empty_deletes_old_binned_for_good(): void
    {
        $asset = $this->lib->replace($this->photo()->id, $this->upload($this->jpeg(red: 20), 'second.jpg'), 'ed');
        $this->lib->delete($asset->id);
        $this->later(31);
        $seen = [];

        $result = $this->lib->emptyBin(30, static function (Asset $a, bool $deleted) use (&$seen): void {
            $seen[] = [$a->id, $deleted];
        });

        $this->assertSame(1, $result->deleted);
        $this->assertSame([], $result->kept);
        $this->assertSame([[$asset->id, true]], $seen);
        $this->assertNull($this->lib->findBinned($asset->id));
        $this->assertNull($this->lib->find($asset->id));
        $this->assertDirectoryDoesNotExist($this->assetRoot . '/bin/' . $asset->id);
        $this->assertDirectoryDoesNotExist($this->assetRoot . '/versions/' . $asset->id);
        $this->assertSame([], $this->publicFiles($asset));
    }

    public function test_empty_keeps_recent(): void
    {
        $asset = $this->photo();
        $this->lib->delete($asset->id);
        $this->later(29);

        $result = $this->lib->emptyBin(30);

        $this->assertSame(0, $result->deleted);
        $this->assertSame([], $result->kept);
        $this->assertNotNull($this->lib->findBinned($asset->id));
        $this->assertNotSame([], $this->binFiles($asset->id));
    }

    public function test_empty_skips_asset_that_a_source_still_reports(): void
    {
        $asset = $this->photo();
        $this->usage->register(AssetUsageTest::source('revisions', [$asset->id => [
            new AssetUsageEntry('Home page', null, 'revisions', 'earlier revision', blocksDelete: false),
        ]]));
        $this->lib->delete($asset->id); // a non-blocking usage lets it bin
        $this->later(31);

        $result = $this->lib->emptyBin(30);

        $this->assertSame(0, $result->deleted);
        $this->assertSame($asset->id, $result->kept[0]['id']);
        $this->assertStringContainsString('revisions', $result->kept[0]['reason']);
        $this->assertNotNull($this->lib->findBinned($asset->id));
    }

    public function test_empty_skips_original_with_live_copy(): void
    {
        $original = $this->photo();
        $copy = $this->lib->makeCopy($original->id, 'ed');
        // A library that does not know about copies (no usage sources) bins the original anyway.
        $this->lib(withUsage: false)->delete($original->id);
        $this->later(31);

        $result = $this->lib->emptyBin(30);

        $this->assertSame(0, $result->deleted);
        $this->assertSame([['id' => $original->id, 'reason' => $result->kept[0]['reason']]], $result->kept);
        $this->assertStringContainsString(CopyReferences::NAME, $result->kept[0]['reason']);
        $this->assertNotNull($this->lib->findBinned($original->id));
        $this->assertNotNull($this->lib->find($copy->id));
    }

    public function test_delete_without_bin_dir_removes_as_before(): void
    {
        $lib = $this->lib(['bin_dir' => null]);
        $asset = $lib->replace($lib->upload($this->upload($this->jpeg(), 'a.jpg'), null)->id, $this->upload($this->jpeg(red: 20), 'b.jpg'), null);

        $lib->delete($asset->id);

        $this->assertNull($lib->find($asset->id));
        $this->assertNull($lib->findBinned($asset->id));
        $this->assertNull($lib->binDir());
        $this->assertSame([], $this->publicFiles($asset));
        $this->assertDirectoryDoesNotExist($this->assetRoot . '/versions/' . $asset->id);
        $this->assertDirectoryDoesNotExist($this->assetRoot . '/bin');
    }

    public function test_discard_deletes_for_good_with_a_bin(): void
    {
        $asset = $this->photo();

        $this->lib->discard($asset->id);

        $this->assertNull($this->lib->findBinned($asset->id));
        $this->assertSame([], $this->publicFiles($asset));
        $this->assertDirectoryDoesNotExist($this->assetRoot . '/bin/' . $asset->id);
    }

    public function test_non_blocking_usage_lets_delete_bin(): void
    {
        $asset = $this->photo();
        $this->usage->register(AssetUsageTest::source('revisions', [$asset->id => [
            new AssetUsageEntry('Home page', null, 'revisions', 'earlier revision', blocksDelete: false),
        ]]));
        $this->assertSame([], $this->usage->blocking($asset->id));

        $this->lib->delete($asset->id);

        $this->assertNotNull($this->lib->findBinned($asset->id));
    }

    public function test_blocking_usage_refuses(): void
    {
        $asset = $this->photo();
        $blocking = new AssetUsageEntry('Home page', '/admin/page/1', 'pages');
        $this->usage->register(AssetUsageTest::source('pages', [$asset->id => [
            new AssetUsageEntry('Old home page', null, 'revisions', 'earlier revision', blocksDelete: false),
            $blocking,
        ]]));
        $this->assertTrue($blocking->blocksDelete);

        try {
            $this->lib->delete($asset->id);
            $this->fail('delete() should refuse an asset with a blocking usage');
        } catch (AssetInUseException $e) {
            $this->assertSame([$blocking], $e->usages);
        }

        $this->assertNotNull($this->lib->find($asset->id));
        $this->assertNull($this->lib->findBinned($asset->id));
        $this->assertSame([], $this->binFiles($asset->id));
    }

    public function test_binned_at_is_stored_in_utc(): void
    {
        $this->clock = new \DateTimeImmutable('2026-10-01T12:00:00+02:00');
        $asset = $this->photo();

        $this->lib->delete($asset->id);

        // Text order is time order only when every value has the same offset.
        $this->assertSame('2026-10-01T10:00:00+00:00', $this->lib->findBinned($asset->id)?->binnedAt);
    }

    public function test_empty_in_chunks_oldest_first(): void
    {
        $ids = [];
        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $name) {
            $ids[] = $id = $this->photo($name)->id;
            $this->lib->delete($id);
            $this->later(1);
        }
        $this->later(31);

        $first = $this->lib->emptyBin(30, limit: 2);

        $this->assertSame([2, [], 1], [$first->deleted, $first->kept, $first->left]);
        $this->assertNull($this->lib->findBinned($ids[0]));
        $this->assertNull($this->lib->findBinned($ids[1]));
        $this->assertNotNull($this->lib->findBinned($ids[2]));

        $rest = $this->lib->emptyBin(30, limit: 2);

        $this->assertSame([1, 0], [$rest->deleted, $rest->left]);
        $this->assertNull($this->lib->findBinned($ids[2]));
    }

    public function test_empty_with_offset_skips_the_first_old_assets(): void
    {
        $kept = $this->photo('kept.jpg');
        $this->usage->register(AssetUsageTest::source('revisions', [$kept->id => [
            new AssetUsageEntry('Home page', null, 'revisions', 'earlier revision', blocksDelete: false),
        ]]));
        $this->lib->delete($kept->id);
        $this->later(1);
        $other = $this->photo('other.jpg');
        $this->lib->delete($other->id);
        $this->later(31);

        $first = $this->lib->emptyBin(30, limit: 1);
        // Kept assets stay ahead of the next chunk: it starts after them.
        $next = $this->lib->emptyBin(30, limit: 1, offset: count($first->kept));

        $this->assertSame([0, 1, 1], [$first->deleted, count($first->kept), $first->left]);
        $this->assertSame([1, [], 0], [$next->deleted, $next->kept, $next->left]);
        $this->assertNotNull($this->lib->findBinned($kept->id));
        $this->assertNull($this->lib->findBinned($other->id));
    }

    public function test_poster_of_a_binned_asset_is_kept_in_the_bin(): void
    {
        $lib = $this->lib;
        $this->usage->register(new PosterReferences(static fn (): MediaLibrary => $lib), last: true);
        $poster = $this->photo('poster.jpg');
        $clip = $this->lib->upload($this->upload("notes\n", 'clip.txt'), 'ed');
        $this->lib->update($clip->id, ['poster' => $poster->id]);
        $this->usage->register(AssetUsageTest::source('revisions', [$clip->id => [
            new AssetUsageEntry('Home page', null, 'revisions', 'earlier revision', blocksDelete: false),
        ]]));

        try {
            $this->lib->delete($poster->id);
            $this->fail('the poster of a live asset is refused');
        } catch (AssetInUseException) {
        }
        $this->lib->delete($clip->id);
        $this->assertEquals(
            [new AssetUsageEntry('clip.txt', null, PosterReferences::NAME, PosterReferences::NOTE_BINNED, blocksDelete: false)],
            $this->usage->of($poster->id),
        );
        $this->lib->delete($poster->id); // its asset is in the bin: the poster may follow
        $this->later(31);

        $result = $this->lib->emptyBin(30);

        $this->assertSame(0, $result->deleted);
        $kept = array_column($result->kept, 'reason', 'id');
        $this->assertStringContainsString(PosterReferences::NOTE_BINNED, $kept[$poster->id]);
        $this->assertNotNull($this->lib->findBinned($poster->id));
    }
}
