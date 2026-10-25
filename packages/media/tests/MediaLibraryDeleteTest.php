<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Media\Tests\Usage\AssetUsageTest;

#[RequiresPhpExtension('gd')]
final class MediaLibraryDeleteTest extends TestCase
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

    public function test_a_used_asset_is_refused_and_kept(): void
    {
        $usage = new AssetUsage();
        $library = $this->library(usage: $usage);
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $entry = new AssetUsageEntry('Home page', '/admin/page/1', 'pages');
        $usage->register(AssetUsageTest::source('pages', [$asset->id => [$entry]]));

        try {
            $library->delete($asset->id);
            $this->fail('delete() should refuse a used asset');
        } catch (AssetInUseException $e) {
            $this->assertSame([$entry], $e->usages);
        }

        $this->assertNotNull($library->find($asset->id));
        $this->assertFileExists($library->absolute($asset->path));
    }

    public function test_an_unused_asset_is_deleted(): void
    {
        $usage = new AssetUsage();
        $usage->register(AssetUsageTest::source('pages', []));
        $library = $this->library(usage: $usage);
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);

        $library->delete($asset->id);

        $this->assertNull($library->find($asset->id));
        $this->assertFileDoesNotExist($library->absolute($asset->path));
    }
}
