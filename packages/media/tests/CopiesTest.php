<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\CopyReferences;

/** Copies that know their original: derive(), makeCopy(), copies(), copyCounts(), and private imports. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class CopiesTest extends TestCase
{
    use MediaFixtures;

    private MediaLibrary $lib;
    private AssetUsage $usage;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->usage = new AssetUsage();
        $this->lib = $this->library(
            ['versions_dir' => $this->assetRoot . '/versions', 'private_dir' => $this->assetRoot . '/private'],
            new VariantGenerator(new GdBackend(), [480], 75),
            $this->usage,
        );
        $lib = $this->lib;
        $this->usage->register(new CopyReferences(static fn (): MediaLibrary => $lib), last: true);
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function jpeg(int $width = 60, int $height = 40, int $red = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, 80, 40));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** A file on disk outside the library, as a maker would write it. */
    private function fileWith(string $bytes, string $name = 'made.jpg'): string
    {
        $dir = $this->assetRoot . '/made';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir . '/' . bin2hex(random_bytes(4)) . '-' . $name;
        file_put_contents($path, $bytes);

        return $path;
    }

    private function original(): Asset
    {
        return $this->lib->upload($this->upload($this->jpeg(), 'photo.jpg'), 'ed');
    }

    private function bytesOf(Asset $asset): string
    {
        return (string) file_get_contents((string) $this->lib->file($asset));
    }

    public function test_derive_links_copy_to_original(): void
    {
        $original = $this->original();
        $made = $this->jpeg(60, 40, 10);

        $copy = $this->lib->derive($original->id, $this->fileWith($made), 'ed', 'watermark', ['line' => 'x']);

        $this->assertNotSame($original->id, $copy->id);
        $this->assertSame($original->id, $copy->derivedFrom);
        $this->assertSame('watermark', $copy->maker);
        $this->assertSame(['line' => 'x'], $copy->makerParams);
        $this->assertSame(1, $copy->derivedVersion);
        $this->assertTrue($copy->isCopy());
        $this->assertFalse($original->isCopy());
        $this->assertSame('image/jpeg', $copy->mime);
        $this->assertFalse($copy->private);
        $this->assertFileExists($this->lib->absolute($copy->path));
        $this->assertSame($made, $this->bytesOf($copy));

        $found = $this->lib->find($copy->id);
        $this->assertNotNull($found);
        $this->assertSame($original->id, $found->derivedFrom);
        $this->assertSame('watermark', $found->maker);
        $this->assertSame(['line' => 'x'], $found->makerParams);
        $this->assertSame(1, $found->derivedVersion);
    }

    public function test_derive_from_a_copy_points_at_the_root(): void
    {
        $original = $this->original();
        $copy = $this->lib->derive($original->id, $this->fileWith($this->jpeg()), null, 'watermark');

        $second = $this->lib->derive($copy->id, $this->fileWith($this->jpeg()), null, 'watermark');

        $this->assertSame($original->id, $second->derivedFrom);
    }

    public function test_derive_takes_the_originals_private_flag_unless_told(): void
    {
        $original = $this->lib->importFile($this->fileWith($this->jpeg()), 'secret.jpg', null, private: true);

        $this->assertTrue($this->lib->derive($original->id, $this->fileWith($this->jpeg()), null)->private);
        $this->assertFalse($this->lib->derive($original->id, $this->fileWith($this->jpeg()), null, private: false)->private);
    }

    public function test_derive_from_an_unknown_asset_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lib->derive(str_repeat('a', 32), $this->fileWith($this->jpeg()), null);
    }

    public function test_make_copy_of_original_takes_raw_file_and_edits(): void
    {
        $original = $this->lib->rotate($this->original()->id, 1);

        $copy = $this->lib->makeCopy($original->id, 'ed');

        $this->assertSame($original->id, $copy->derivedFrom);
        $this->assertSame('copy', $copy->maker);
        $this->assertSame([], $copy->makerParams);
        $this->assertSame(1, $copy->rotation);
        $this->assertSame($original->focalX, $copy->focalX);
        $this->assertSame($original->focalY, $copy->focalY);
        $this->assertSame($this->bytesOf($original), $this->bytesOf($copy));
        // Edited like any asset: its own rendered master, turned like the original's.
        $this->assertNotNull($copy->master);
        $this->assertStringContainsString($copy->id, $copy->master->path);
        $this->assertSame([$original->master?->width, $original->master?->height], [$copy->master->width, $copy->master->height]);
    }

    public function test_copy_of_copy_is_sibling_with_same_maker(): void
    {
        $original = $this->original();
        $copy = $this->lib->derive($original->id, $this->fileWith($this->jpeg(60, 40, 10)), null, 'watermark', ['line' => '© Ed']);
        $copy = $this->lib->flip($copy->id, true);

        $sibling = $this->lib->makeCopy($copy->id, null);

        $this->assertSame($original->id, $sibling->derivedFrom);
        $this->assertSame('watermark', $sibling->maker);
        $this->assertSame(['line' => '© Ed'], $sibling->makerParams);
        $this->assertTrue($sibling->mirrored);
        $this->assertSame($this->bytesOf($copy), $this->bytesOf($sibling));
    }

    public function test_copies_lists_newest_first(): void
    {
        $times = ['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04'];
        $this->lib = $this->library(
            ['versions_dir' => $this->assetRoot . '/versions'],
            usage: $this->usage,
            now: static function () use (&$times): \DateTimeImmutable {
                return new \DateTimeImmutable((string) array_shift($times));
            },
        );
        $original = $this->original();
        $other = $this->original();
        $first = $this->lib->derive($original->id, $this->fileWith($this->jpeg()), null);
        $second = $this->lib->derive($original->id, $this->fileWith($this->jpeg()), null);

        $this->assertSame([$second->id, $first->id], array_map(static fn (Asset $a) => $a->id, $this->lib->copies($original->id)));
        $this->assertSame([], $this->lib->copies($other->id));
    }

    public function test_copy_counts(): void
    {
        $a = $this->original();
        $b = $this->original();
        $c = $this->original();
        $this->lib->derive($a->id, $this->fileWith($this->jpeg()), null);
        $this->lib->derive($a->id, $this->fileWith($this->jpeg()), null);
        $this->lib->derive($b->id, $this->fileWith($this->jpeg()), null);

        $this->assertSame([$a->id => 2, $b->id => 1, $c->id => 0], $this->lib->copyCounts([$a->id, $b->id, $c->id]));
        $this->assertSame([], $this->lib->copyCounts([]));
    }

    public function test_delete_of_original_with_copies_is_refused(): void
    {
        $original = $this->original();
        $copy = $this->lib->derive($original->id, $this->fileWith($this->jpeg()), null);
        $copy = $this->lib->update($copy->id, ['title' => 'Watermarked']);

        try {
            $this->lib->delete($original->id);
            $this->fail('delete() should refuse an original with copies');
        } catch (AssetInUseException $e) {
            $this->assertSame(['copies'], array_map(static fn ($u) => $u->source, $e->usages));
            $this->assertSame('Watermarked', $e->usages[0]->label);
            $this->assertNull($e->usages[0]->url);
            $this->assertSame('copy', $e->usages[0]->note);
        }
        $this->assertNotNull($this->lib->find($original->id));

        // The copy itself is nobody's original: it can go, and then the original can.
        $this->lib->delete($copy->id);
        $this->lib->delete($original->id);
        $this->assertNull($this->lib->find($original->id));
    }

    public function test_import_file_private(): void
    {
        $asset = $this->lib->importFile($this->fileWith("%PDF-1.4\n%%EOF\n", 'a.pdf'), 'a.pdf', null, private: true);

        $this->assertTrue($asset->private);
        $this->assertSame('a.pdf', $asset->originalName);
        $this->assertStringStartsWith($this->assetRoot . '/private/', (string) $this->lib->file($asset));
        $this->assertFileExists((string) $this->lib->file($asset));
        $this->assertFileDoesNotExist($this->lib->absolute($asset->path));
    }

    public function test_the_copy_columns_are_declared_with_their_types(): void
    {
        $fields = $this->lib->definition()->fields;

        $this->assertSame('string', $fields['derived_from']->type);
        $this->assertSame('string', $fields['maker']->type);
        $this->assertSame('string', $fields['maker_params']->type);
        $this->assertSame('number', $fields['derived_version']->type);
        $this->assertNotContains('derived_version', Asset::TEXT_COLUMNS);
        foreach (['derived_from', 'maker', 'maker_params', 'derived_version'] as $name) {
            $this->assertContains($name, Asset::RESERVED);
        }
    }

    public function test_copies_start_with_the_alt_and_title(): void
    {
        $original = $this->lib->update($this->original()->id, ['alt' => ['en' => 'A red square', 'de' => 'Ein rotes Quadrat'], 'title' => 'Red']);

        $derived = $this->lib->derive($original->id, $this->fileWith($this->jpeg(60, 40, 10)), null, 'watermark');
        $copy = $this->lib->makeCopy($original->id, null);
        $edited = $this->lib->update($copy->id, ['alt' => ['en' => 'The copy'], 'title' => 'Copy title']);
        $sibling = $this->lib->makeCopy($edited->id, null);

        $this->assertSame($original->alt, $derived->alt, 'derive: the original\'s alt in every locale');
        $this->assertSame('Red', $derived->title);
        $this->assertSame($original->alt, $copy->alt);
        $this->assertSame('Red', $copy->title);
        $this->assertSame(['en' => 'The copy', 'de' => 'Ein rotes Quadrat'], $sibling->alt, 'a copy of a copy: the copied asset\'s');
        $this->assertSame('Copy title', $sibling->title);
        $this->assertSame($original->alt, $this->lib->find($derived->id)?->alt, 'stored');
    }

    public function test_derive_refuses_the_reserved_maker_copy(): void
    {
        $original = $this->original();

        try {
            $this->lib->derive($original->id, $this->fileWith($this->jpeg()), null, MediaLibrary::MAKER_COPY);
            $this->fail('derive() should refuse the maker "copy"');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('reserved', $e->getMessage());
        }
        $this->assertSame([], $this->lib->copies($original->id));
        $this->assertSame(MediaLibrary::MAKER_COPY, $this->lib->makeCopy($original->id, null)->maker, 'makeCopy() still makes them');
    }

    public function test_link_copy_marks_an_existing_asset_as_a_copy(): void
    {
        $original = $this->lib->replace($this->original()->id, $this->upload($this->jpeg(red: 20), 'second.jpg'), 'ed');
        $other = $this->lib->update($this->lib->upload($this->upload($this->jpeg(60, 40, 10), 'old-watermarked.jpg'), 'ed')->id, ['title' => 'Kept']);
        $bytes = $this->bytesOf($other);

        $linked = $this->lib->linkCopy($other->id, $original->id, 'watermark', ['line' => '© Ed']);

        $this->assertSame($other->id, $linked->id);
        $this->assertSame($original->id, $linked->derivedFrom);
        $this->assertSame('watermark', $linked->maker);
        $this->assertSame(['line' => '© Ed'], $linked->makerParams);
        $this->assertSame(2, $linked->derivedVersion, 'made from the original\'s current version');
        $this->assertSame('Kept', $linked->title);
        $this->assertSame($bytes, $this->bytesOf($linked), 'the file stays');
        $this->assertFalse($this->lib->isStale($linked));
        $this->assertSame([$other->id], array_map(static fn (Asset $c) => $c->id, $this->lib->copies($original->id)));
        $this->assertSame($original->id, $this->lib->find($other->id)?->derivedFrom, 'stored');

        $plain = $this->lib->linkCopy($this->original()->id, $original->id);
        $this->assertNull($plain->maker, 'no maker: a copy remake() cannot make again');
        $this->assertFalse($this->lib->canRemake($plain));
    }

    public function test_link_copy_refusals(): void
    {
        $original = $this->original();
        $copy = $this->lib->makeCopy($original->id, null);
        $other = $this->original();
        $binned = $this->original();
        $this->lib(['bin_dir' => $this->assetRoot . '/bin'])->delete($binned->id);
        $unknown = str_repeat('a', 32);

        $cases = [
            'itself' => [$other->id, $other->id, null, 'itself'],
            'the maker copy' => [$other->id, $original->id, MediaLibrary::MAKER_COPY, 'reserved'],
            'a copy as original' => [$other->id, $copy->id, null, 'itself a copy'],
            'an original with copies' => [$original->id, $other->id, null, 'copies of its own'],
            'an unknown asset' => [$unknown, $original->id, null, 'Unknown asset'],
            'an unknown original' => [$other->id, $unknown, null, 'Unknown asset'],
            'a binned asset' => [$binned->id, $original->id, null, 'in the bin'],
            'a binned original' => [$other->id, $binned->id, null, 'in the bin'],
        ];
        foreach ($cases as $case => [$id, $originalId, $maker, $message]) {
            try {
                $this->lib->linkCopy($id, $originalId, $maker);
                $this->fail("linkCopy() should refuse {$case}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $case);
            }
        }
        $this->assertNull($this->lib->find($other->id)?->derivedFrom, 'nothing changed');
        $this->assertNull($this->lib->find($original->id)?->derivedFrom);
    }

    public function test_link_copy_counts_copies_in_the_bin(): void
    {
        $original = $this->original();
        $copy = $this->lib->makeCopy($original->id, null);
        $this->lib(['bin_dir' => $this->assetRoot . '/bin'])->delete($copy->id);

        $this->expectExceptionMessage('copies of its own');
        $this->lib->linkCopy($original->id, $this->original()->id);
    }

    /** @param array<string, mixed> $config */
    private function lib(array $config): MediaLibrary
    {
        return $this->library(
            $config + ['versions_dir' => $this->assetRoot . '/versions', 'private_dir' => $this->assetRoot . '/private'],
            new VariantGenerator(new GdBackend(), [480], 75),
        );
    }
}
