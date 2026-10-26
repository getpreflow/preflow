<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\AssetUploadException;
use Preflow\Media\AssetVersion;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\ImageBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;

/** Replacing an asset's file in place: versions, restore, delete and prune of versions. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class ReplaceTest extends TestCase
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

    private function lib(?VariantGenerator $variants = null): MediaLibrary
    {
        return $this->library(
            ['versions_dir' => $this->assetRoot . '/versions', 'private_dir' => $this->assetRoot . '/private'],
            $variants ?? new VariantGenerator(new GdBackend(), [480], 75),
        );
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /** @return list<string> */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }
        sort($out);

        return $out;
    }

    public function test_replace_keeps_the_meta_and_moves_the_old_file_to_a_version(): void
    {
        $library = $this->lib();
        $poster = $library->upload($this->upload($this->png(20, 20), 'poster.png'), null);
        $asset = $library->upload($this->upload($this->png(1200, 800), 'Old.png'), 'a@x');
        $library->update($asset->id, ['alt' => ['en' => 'Sea', 'de' => 'Meer'], 'title' => 'View', 'focal_x' => 30, 'focal_y' => 70, 'poster' => $poster->id]);
        $old = $library->find($asset->id);
        $this->assertNotSame([], $old->variants);
        $oldFiles = [$library->absolute($old->path), ...array_map(fn ($v) => $library->absolute($v->path), $old->variants)];

        $new = $library->replace($asset->id, $this->upload($this->png(900, 600), 'New.png'), 'b@x');

        $this->assertSame($asset->id, $new->id);
        $this->assertSame(['en' => 'Sea', 'de' => 'Meer'], $new->alt);
        $this->assertSame('View', $new->title);
        $this->assertSame([30, 70], [$new->focalX, $new->focalY]);
        $this->assertSame($poster->id, $new->posterId);
        $this->assertFalse($new->private);
        $this->assertSame([$old->createdAt, 'a@x'], [$new->createdAt, $new->createdBy]);

        $this->assertSame(2, $new->version);
        $this->assertSame(substr($old->path, 0, -4) . '-v2.png', $new->path);
        $this->assertSame('New.png', $new->originalName);
        $this->assertSame([900, 600], [$new->width, $new->height]);
        $this->assertFileExists($library->absolute($new->path));
        $this->assertNotSame([], $new->variants);
        foreach ($new->variants as $v) {
            $this->assertStringContainsString($asset->id . '-v2-', $v->path);
            $this->assertFileExists($library->absolute($v->path));
        }
        foreach ($oldFiles as $file) {
            $this->assertFileDoesNotExist($file);
        }

        $file = $library->versionFile($asset->id, 1);
        $this->assertSame($this->assetRoot . '/versions/' . $asset->id . '/v1.png', $file);
        $this->assertFileExists($file);
        $this->assertSame($old->size, filesize($file));

        $versions = $library->versions($asset->id);
        $this->assertCount(1, $versions);
        $this->assertInstanceOf(AssetVersion::class, $versions[0]);
        $this->assertSame([1, 'image/png', $old->size, 1200, 800], [$versions[0]->number, $versions[0]->mime, $versions[0]->size, $versions[0]->width, $versions[0]->height]);
        $this->assertSame(30, $versions[0]->meta['focal_x']);
        $this->assertSame(70, $versions[0]->meta['focal_y']);
        $this->assertSame('Old.png', $versions[0]->meta['original_name']);
        $this->assertSame('b@x', $versions[0]->createdBy);
        $this->assertEquals($new, $library->find($asset->id));
    }

    public function test_replace_from_a_path(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(40, 30), 'a.png'), null);
        $source = $this->assetRoot . '/import.png';
        file_put_contents($source, $this->png(50, 20));

        $new = $library->replace($asset->id, $source, null);

        $this->assertSame([50, 20, 'import.png'], [$new->width, $new->height, $new->originalName]);
        $this->assertFileExists($source); // the source stays where it is
    }

    public function test_a_different_kind_is_refused_and_nothing_changes(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(40, 30), 'a.png'), null);
        $before = $this->filesUnder($this->assetRoot . '/media');

        try {
            $library->replace($asset->id, $this->upload($this->pdf(), 'b.pdf'), null);
            $this->fail('A PDF must not replace an image.');
        } catch (AssetUploadException) {
        }

        $this->assertEquals($asset, $library->find($asset->id));
        $this->assertSame($before, $this->filesUnder($this->assetRoot . '/media'));
        $this->assertSame([], array_filter($this->filesUnder($this->assetRoot . '/versions'), static fn ($f) => !str_ends_with($f, '.lock')));
    }

    public function test_restore_after_two_replaces(): void
    {
        $library = $this->lib();
        $v1 = $this->png(300, 200);
        $asset = $library->upload($this->upload($v1, 'one.png'), null);
        $library->update($asset->id, ['focal_x' => 10, 'focal_y' => 20, 'alt' => ['en' => 'First']]);
        $library->replace($asset->id, $this->upload($this->png(400, 200), 'two.png'), null);
        $library->update($asset->id, ['focal_x' => 80, 'focal_y' => 90, 'alt' => ['en' => 'Current']]);
        $library->replace($asset->id, $this->upload($this->png(500, 200), 'three.png'), null);

        $restored = $library->restore($asset->id, 1, 'r@x');

        $this->assertSame(4, $restored->version);
        $this->assertStringEndsWith($asset->id . '-v4.png', $restored->path);
        $this->assertSame($v1, file_get_contents($library->absolute($restored->path)));
        $this->assertSame([300, 200, 'one.png'], [$restored->width, $restored->height, $restored->originalName]);
        $this->assertSame([3, 2, 1], array_map(static fn (AssetVersion $v) => $v->number, $library->versions($asset->id)));
        $this->assertSame([10, 20], [$restored->focalX, $restored->focalY]);
        $this->assertSame(['en' => 'Current'], $restored->alt);
        // The file that was current (v3) is kept as a version.
        $this->assertSame(500, $library->versions($asset->id)[0]->width);
        $this->assertFileExists($library->versionFile($asset->id, 3));
    }

    public function test_a_private_asset_keeps_its_versions_only_in_the_versions_dir(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->pdf(), 'Guide.pdf'), null, true);

        $new = $library->replace($asset->id, $this->upload($this->pdf() . "%2\n", 'Guide 2.pdf'), null);

        $this->assertTrue($new->private);
        $this->assertSame([], $this->filesUnder($this->assetRoot . '/media'));
        $this->assertSame([$new->path], $this->filesUnder($this->assetRoot . '/private'));
        $this->assertSame([$asset->id . '/v1.pdf'], array_values(array_filter($this->filesUnder($this->assetRoot . '/versions'), static fn ($f) => !str_ends_with($f, '.lock'))));
    }

    public function test_prune_keeps_the_newest_earlier_versions(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        foreach ([11, 12, 13] as $w) {
            $current = $library->replace($asset->id, $this->upload($this->png($w, 10), 'a.png'), null);
        }

        $this->assertSame(2, $library->prune($asset->id, 1));
        $this->assertSame([3], array_map(static fn (AssetVersion $v) => $v->number, $library->versions($asset->id)));
        $this->assertNull($library->versionFile($asset->id, 1));
        $this->assertFileDoesNotExist($this->assetRoot . '/versions/' . $asset->id . '/v1.png');
        $this->assertFileDoesNotExist($this->assetRoot . '/versions/' . $asset->id . '/v2.png');
        $this->assertFileExists($library->versionFile($asset->id, 3));
        $this->assertFileExists($library->absolute($current->path));
        $this->assertSame(0, $library->prune($asset->id, 1));

        $this->assertSame(1, $library->prune($asset->id, 0));
        $this->assertSame([], $library->versions($asset->id));
        $this->assertFileExists($library->absolute($current->path));
    }

    public function test_prune_older_than_days(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $library->replace($asset->id, $this->upload($this->png(11, 10), 'a.png'), null);
        $library->replace($asset->id, $this->upload($this->png(12, 10), 'a.png'), null);
        // Make v1 look archived 40 days ago.
        $data = $library->find($asset->id)->toData();
        $data['versions'][1]['created_at'] = (new \DateTimeImmutable('-40 days'))->format(DATE_ATOM);
        (new \ReflectionMethod($library, 'save'))->invoke($library, $data);

        $this->assertSame(1, $library->prune($asset->id, 0, 30));
        $this->assertSame([2], array_map(static fn (AssetVersion $v) => $v->number, $library->versions($asset->id)));
    }

    public function test_delete_version(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $library->replace($asset->id, $this->upload($this->png(11, 10), 'a.png'), null);
        $library->replace($asset->id, $this->upload($this->png(12, 10), 'a.png'), null);

        $library->deleteVersion($asset->id, 1);
        $library->deleteVersion($asset->id, 9); // unknown: nothing happens

        $this->assertSame([2], array_map(static fn (AssetVersion $v) => $v->number, $library->versions($asset->id)));
        $this->assertFileDoesNotExist($this->assetRoot . '/versions/' . $asset->id . '/v1.png');
        $this->assertNull($library->versionFile($asset->id, 1));
        // Numbers only grow: the next replace is v4, never a reused number.
        $this->assertSame(4, $library->replace($asset->id, $this->upload($this->png(13, 10), 'a.png'), null)->version);
    }

    public function test_delete_and_discard_remove_the_versions_folder(): void
    {
        $library = $this->lib();
        foreach (['delete', 'discard'] as $method) {
            $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
            $library->replace($asset->id, $this->upload($this->png(11, 10), 'a.png'), null);
            $this->assertDirectoryExists($this->assetRoot . '/versions/' . $asset->id);

            $library->{$method}($asset->id);

            $this->assertDirectoryDoesNotExist($this->assetRoot . '/versions/' . $asset->id, $method);
            $this->assertNull($library->find($asset->id));
        }
        $this->assertSame([], $this->filesUnder($this->assetRoot . '/media'));
    }

    public function test_a_failing_variant_backend_leaves_the_new_file_and_the_old_version(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(600, 400), 'a.png'), null);
        $failing = $this->lib(new VariantGenerator(new class implements ImageBackend {
            public function supports(string $format): bool
            {
                return $format === 'webp';
            }

            public function writeVariants(string $src, array $jobs, int $quality): array
            {
                file_put_contents($jobs[0]['dest'], 'half'); // dies after writing a file
                throw new \RuntimeException('Out of memory');
            }
        }, [480], 75));

        $new = $failing->replace($asset->id, $this->upload($this->png(500, 400), 'b.png'), null);

        $this->assertSame(2, $new->version);
        $this->assertSame([], $new->variants);
        $this->assertEquals($new, $failing->find($asset->id));
        $this->assertFileExists($failing->absolute($new->path));
        $this->assertSame([500, 400], [$new->width, $new->height]);
        $this->assertFileExists($failing->versionFile($asset->id, 1));
        $this->assertSame([$new->path], array_values(array_filter(
            $this->filesUnder($this->assetRoot . '/media'),
            static fn ($f) => str_contains($f, $asset->id),
        )));
    }

    public function test_a_failing_hook_keeps_the_replace_and_notes_the_error(): void
    {
        $backend = new class implements ImageBackend {
            public function supports(string $format): bool
            {
                return $format === 'webp';
            }

            public function writeVariants(string $src, array $jobs, int $quality): array
            {
                if (str_contains($jobs[0]['dest'], '.tmp-')) {
                    throw new \RuntimeException('Encoder crashed'); // the warm-up (one variant on demand) dies
                }
                foreach ($jobs as $job) {
                    file_put_contents($job['dest'], 'x');
                }

                return array_fill(0, count($jobs), true);
            }
        };
        $library = $this->library(
            ['versions_dir' => $this->assetRoot . '/versions', 'warm_on_upload' => true, 'warm_widths' => [240]],
            new VariantGenerator($backend, [480], 75),
        );
        $asset = $library->upload($this->upload($this->png(100, 50), 'a.png'), null); // too narrow to warm 240
        $log = ini_set('error_log', $this->assetRoot . '/php.log');

        try {
            $new = $library->replace($asset->id, $this->upload($this->png(500, 400), 'b.png'), null);
        } finally {
            ini_set('error_log', (string) $log);
        }

        $this->assertSame(2, $new->version);
        $this->assertFileExists($library->absolute($new->path));
        $this->assertFileExists($library->versionFile($asset->id, 1));
        $this->assertSame('Variants could not be made after the replace: RuntimeException: Encoder crashed', $new->note);
        $this->assertEquals($new, $library->find($asset->id));
        $this->assertStringContainsString('Encoder crashed', (string) file_get_contents($this->assetRoot . '/php.log'));
    }

    public function test_restore_with_a_missing_version_file_is_refused(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $current = $library->replace($asset->id, $this->upload($this->png(11, 10), 'b.png'), null);
        unlink($library->versionFile($asset->id, 1));

        try {
            $library->restore($asset->id, 1, null);
            $this->fail('A missing version file cannot be restored.');
        } catch (AssetUploadException) {
        }

        $this->assertEquals($current, $library->find($asset->id));
        $this->assertFileExists($library->absolute($current->path));
    }

    public function test_a_failing_copy_into_the_versions_dir_changes_nothing(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        mkdir($this->assetRoot . '/versions/' . $asset->id . '/v1.png', 0777, true); // the copy cannot be renamed into place

        try {
            $library->replace($asset->id, $this->upload($this->png(11, 10), 'b.png'), null);
            $this->fail('The replace must fail when the old file cannot be kept.');
        } catch (AssetUploadException) {
        }

        $this->assertEquals($asset, $library->find($asset->id));
        $this->assertFileExists($library->absolute($asset->path));
        $this->assertFileDoesNotExist($library->absolute(substr($asset->path, 0, -4) . '-v2.png'));
        $this->assertSame([], array_filter($this->filesUnder($this->assetRoot . '/versions'), static fn ($f) => str_contains($f, '.tmp-')));
    }

    public function test_delete_removes_leftover_variants_of_earlier_files(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(600, 400), 'a.png'), null);
        $other = $library->upload($this->upload($this->png(600, 400), 'b.png'), null);
        $new = $library->replace($asset->id, $this->upload($this->png(500, 400), 'c.png'), null);
        $stray = $library->absolute(dirname($new->path) . '/' . $asset->id . '-480.webp'); // made on demand for the old file mid-replace
        file_put_contents($stray, 'x');

        $library->delete($asset->id);

        $this->assertFileDoesNotExist($stray);
        $this->assertSame([], array_filter($this->filesUnder($this->assetRoot . '/media'), static fn ($f) => str_contains($f, $asset->id)));
        $this->assertFileExists($library->absolute($other->path));
        foreach ($other->variants as $v) {
            $this->assertFileExists($library->absolute($v->path));
        }
    }

    public function test_without_a_versions_dir_replace_is_refused(): void
    {
        $library = $this->library([], null);
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);

        $this->expectException(AssetUploadException::class);
        try {
            $library->replace($asset->id, $this->upload($this->png(11, 10), 'b.png'), null);
        } finally {
            $this->assertEquals($asset, $library->find($asset->id));
        }
    }

    #[RequiresFunction('pcntl_fork')]
    public function test_two_replaces_at_once_keep_both_old_files(): void
    {
        $library = $this->lib(new VariantGenerator(null, [], 75));
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);

        $pids = [];
        foreach ([11, 12] as $w) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->lib(new VariantGenerator(null, [], 75))->replace($asset->id, $this->upload($this->png($w, 10), 'x.png'), null);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $current = $library->find($asset->id);
        $this->assertSame(3, $current->version);
        $this->assertSame([2, 1], array_map(static fn (AssetVersion $v) => $v->number, $library->versions($asset->id)));
        $this->assertFileExists($library->versionFile($asset->id, 1));
        $this->assertFileExists($library->versionFile($asset->id, 2));
        $this->assertFileExists($library->absolute($current->path));
        $this->assertEqualsCanonicalizing([11, 12], [$current->width, $library->versions($asset->id)[0]->width]);
    }

    public function test_regenerate_with_a_stale_asset_keeps_a_replace(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(600, 400), 'a.png'), null);
        $stale = $library->find($asset->id); // media:variants --all read the list before the replace
        $new = $library->replace($asset->id, $this->upload($this->png(500, 400), 'b.png'), null);

        $after = $library->regenerate($stale);

        $current = $library->find($asset->id);
        $this->assertEquals($after, $current);
        $this->assertSame([2, $new->path], [$current->version, $current->path]);
        $this->assertSame([1], array_map(static fn (AssetVersion $v) => $v->number, $current->versions));
        $this->assertFileExists($library->absolute($current->path));
        $this->assertFileExists($library->versionFile($asset->id, 1));
        $this->assertNotSame([], $current->variants);
        foreach ($current->variants as $v) {
            $this->assertStringContainsString($asset->id . '-v2-', $v->path);
            $this->assertFileExists($library->absolute($v->path));
        }
    }

    #[RequiresFunction('pcntl_fork')]
    public function test_an_update_during_a_replace_waits_for_it(): void
    {
        $slow = new class implements ImageBackend {
            public function supports(string $format): bool
            {
                return $format === 'webp';
            }

            public function writeVariants(string $src, array $jobs, int $quality): array
            {
                usleep(600000); // the replace's variants take a while, with the record already on the new file
                foreach ($jobs as $job) {
                    file_put_contents($job['dest'], 'x');
                }

                return array_fill(0, count($jobs), true);
            }
        };
        $library = $this->lib(new VariantGenerator(null, [], 75));
        $asset = $library->upload($this->upload($this->png(600, 400), 'a.png'), null);

        $pid = pcntl_fork();
        if ($pid === 0) {
            $this->lib(new VariantGenerator($slow, [480], 75))->replace($asset->id, $this->upload($this->png(500, 400), 'b.png'), null);
            exit(0);
        }
        // Once the replace saved the new file, edit the title (while its variants are being made).
        $deadline = microtime(true) + 5;
        while ($library->find($asset->id)?->version !== 2 && microtime(true) < $deadline) {
            usleep(10000);
        }
        $library->update($asset->id, ['title' => 'Edited']);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));

        $current = $library->find($asset->id);
        $this->assertSame('Edited', $current->title);
        $this->assertSame(2, $current->version);
        $this->assertFileExists($library->absolute($current->path));
        $this->assertNotSame([], $current->variants);
        $this->assertSame([1], array_map(static fn (AssetVersion $v) => $v->number, $current->versions));
    }

    public function test_locks_live_in_one_folder_and_delete_removes_the_lock(): void
    {
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->png(40, 30), 'a.png'), null);
        $library->update($asset->id, ['title' => 'T']);
        $library->regenerate($library->find($asset->id));

        // Touching an asset makes no folder for it: only its lock in .locks/.
        $this->assertSame(['.locks/' . $asset->id . '.lock'], $this->filesUnder($this->assetRoot . '/versions'));

        $library->replace($asset->id, $this->upload($this->png(50, 30), 'b.png'), null);
        $this->assertSame(['.locks/' . $asset->id . '.lock', $asset->id . '/v1.png'], $this->filesUnder($this->assetRoot . '/versions'));

        $library->delete($asset->id);
        $this->assertSame([], $this->filesUnder($this->assetRoot . '/versions'));
    }
}
