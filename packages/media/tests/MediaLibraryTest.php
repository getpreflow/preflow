<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\WritesRefused;
use Preflow\Media\Asset;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Testing\ContextFactory;

/**
 * An upload inside a render that writes nothing (a fragment under a preview token refuses, a
 * whole-page preview or a view as a role skips) leaves no file on the disk: no original, no
 * variant, no record (Task 22 M4).
 */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
final class MediaLibraryTest extends TestCase
{
    use MediaFixtures;

    protected function setUp(): void
    {
        Contexts::reset();
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        $this->removeAssetRoot();
    }

    public function test_an_upload_under_a_refusing_render_writes_no_file(): void
    {
        $library = $this->library();
        $upload = $this->upload($this->png(), 'Sea.png', 'image/png');

        try {
            Contexts::run(ContextFactory::forTest()->refusingWrites(), static fn (): Asset => $library->upload($upload, 'u@x'));
            $this->fail('the upload must be refused');
        } catch (WritesRefused) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $this->files(), 'no file');
        $this->assertSame([], $library->all(), 'no record');
    }

    public function test_an_upload_under_a_skipping_render_writes_no_file(): void
    {
        $library = $this->library();
        $upload = $this->upload($this->png(), 'Sea.png', 'image/png');

        $asset = Contexts::run(ContextFactory::forTest()->skippingWrites(), static fn (): Asset => $library->upload($upload, 'u@x'));

        $this->assertSame('Sea.png', $asset->originalName, 'answered as if stored');
        $this->assertSame([], $this->files(), 'no original, no variant');
        $this->assertSame([], $library->all(), 'no record');

        // Proof: outside such a render the upload writes its file.
        $library->upload($this->upload($this->png(), 'Sea.png', 'image/png'), 'u@x');
        $this->assertNotSame([], $this->files());
    }

    /** @return list<string> every file under the media root */
    private function files(): array
    {
        $root = $this->assetRoot . '/media';
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = (string) $file;
        }

        return $files;
    }
}
