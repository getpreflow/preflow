<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Usage;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Media\MediaLibrary;
use Preflow\Media\MediaServiceProvider;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;

/** A media-only site: the core reports an asset used as a video poster and refuses to delete it. */
#[RequiresPhpExtension('gd')]
final class PosterReferencesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/media_poster_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_deleting_a_poster_is_refused_on_a_media_only_site(): void
    {
        $app = Application::create($this->dir);
        $app->registerProvider(new MediaServiceProvider());
        $c = $app->container();
        $c->instance(DataManager::class, new DataManager(drivers: ['json' => new JsonFileDriver($this->dir . '/store')], defaultDriver: 'json'));
        $library = $c->get(MediaLibrary::class);
        $poster = $library->upload($this->upload($this->png(), 'poster.png'), null);
        $video = $library->upload($this->upload("notes\n", 'clip.txt'), null);
        $library->update($video->id, ['poster' => $poster->id]);

        $this->assertSame(['media', 'copies'], $c->get(AssetUsage::class)->sourceNames());
        try {
            $library->delete($poster->id);
            $this->fail('delete() should refuse an asset used as a poster');
        } catch (AssetInUseException $e) {
            $this->assertEquals([new AssetUsageEntry('clip.txt', null, 'media', 'as poster')], $e->usages);
        }
        $this->assertNotNull($library->find($poster->id));

        $library->delete($video->id); // nothing uses the video
        $library->delete($poster->id); // and now nothing uses the poster
        $this->assertSame([], $library->all());
    }

    public function test_a_source_registered_by_a_provider_listed_before_media_is_kept(): void
    {
        $app = Application::create($this->dir);
        $site = new AssetUsageEntry('Home page', '/admin/page/1', 'site');
        // A site provider that asks for the usage registry before media has registered at all.
        $app->registerProvider(new class ($site) extends ServiceProvider {
            public function __construct(private readonly AssetUsageEntry $entry) {}

            public function register(Container $container): void
            {
                $container->get(AssetUsage::class)->register(AssetUsageTest::source('site', ['*' => [$this->entry]], everything: true));
            }
        });
        $app->registerProvider(new MediaServiceProvider());
        $c = $app->container();
        $c->instance(DataManager::class, new DataManager(drivers: ['json' => new JsonFileDriver($this->dir . '/store')], defaultDriver: 'json'));
        $library = $c->get(MediaLibrary::class);
        $asset = $library->upload($this->upload($this->png(), 'a.png'), null);

        $this->assertSame(['site', 'media', 'copies'], $c->get(AssetUsage::class)->sourceNames());
        try {
            $library->delete($asset->id);
            $this->fail('delete() should refuse an asset the site source reports');
        } catch (AssetInUseException $e) {
            $this->assertEquals([$site], $e->usages);
        }
        $this->assertNotNull($library->find($asset->id));
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function upload(string $bytes, string $name): \Psr\Http\Message\UploadedFileInterface
    {
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name);
    }
}
