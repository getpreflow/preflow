<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Media\MediaConfig;

final class MediaConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/media_cfg_' . bin2hex(random_bytes(4));
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

    /** @param array<string, mixed> $values */
    private function write(string $file, array $values): void
    {
        file_put_contents($this->dir . '/config/' . $file, '<?php return ' . var_export($values, true) . ';');
    }

    /** @return array<string, mixed> */
    private function values(): array
    {
        return [
            'dir' => $this->dir . '/public/files',
            'url' => '/files-public',
            'storage' => 'sqlite',
            'max_bytes' => 1024,
            'max_pixels' => 2000,
            'warm_on_upload' => true,
            'allow_svg' => true,
            'widths' => [320, 640],
            'quality' => 60,
            'private_dir' => $this->dir . '/storage/secret',
            'private_url' => '/secret',
            'versions_dir' => $this->dir . '/storage/old',
        ];
    }

    private function assertValues(MediaConfig $config): void
    {
        $this->assertSame($this->dir . '/public/files', $config->dir);
        $this->assertSame('/files-public', $config->url);
        $this->assertSame('sqlite', $config->storage);
        $this->assertSame(1024, $config->maxBytes);
        $this->assertSame(2000, $config->maxPixels);
        $this->assertTrue($config->warmOnUpload);
        $this->assertTrue($config->allowSvg);
        $this->assertSame([320, 640], $config->widths);
        $this->assertSame(60, $config->quality);
        $this->assertSame($this->dir . '/storage/secret', $config->privateDir);
        $this->assertSame('/secret', $config->privateUrl);
        $this->assertSame($this->dir . '/storage/old', $config->versionsDir);
    }

    public function test_media_php_only(): void
    {
        $this->write('media.php', $this->values());
        $debug = new DebugCollector();

        $config = MediaConfig::fromApp(Application::create($this->dir), $debug);

        $this->assertValues($config);
        $this->assertSame('config/media.php', $config->source());
        $this->assertSame([], $debug->getDeprecations());
    }

    public function test_folio_assets_fallback_logs_one_deprecation(): void
    {
        $this->write('folio.php', ['assets' => $this->values()]);
        $debug = new DebugCollector();

        $config = MediaConfig::fromApp(Application::create($this->dir), $debug);

        $this->assertValues($config);
        $this->assertSame('config/folio.php assets', $config->source());
        $this->assertSame(['config/folio.php "assets" is deprecated; move it to config/media.php'], $debug->getDeprecations());
    }

    public function test_fallback_without_collector_still_reads_the_values(): void
    {
        $this->write('folio.php', ['assets' => $this->values()]);

        $this->assertValues(MediaConfig::fromApp(Application::create($this->dir)));
    }

    public function test_media_php_wins_and_nothing_is_merged(): void
    {
        $this->write('media.php', ['url' => '/m']);
        $this->write('folio.php', ['assets' => ['url' => '/old', 'allow_svg' => true, 'quality' => 90]]);
        $debug = new DebugCollector();

        $config = MediaConfig::fromApp(Application::create($this->dir), $debug);

        $this->assertSame('/m', $config->url);
        $this->assertFalse($config->allowSvg);
        $this->assertSame(75, $config->quality);
        $this->assertSame('config/media.php', $config->source());
        $this->assertSame([], $debug->getDeprecations());
    }

    public function test_without_either_file_the_defaults_are_media_php_and_nothing_is_deprecated(): void
    {
        $this->write('folio.php', ['prefix' => '/folio']); // a folio.php without an "assets" block
        $debug = new DebugCollector();

        $config = MediaConfig::fromApp(Application::create($this->dir), $debug);

        $this->assertSame('config/media.php', $config->source());
        $this->assertSame([], $debug->getDeprecations());
        unlink($this->dir . '/config/folio.php');
        $this->assertSame('config/media.php', MediaConfig::fromApp(Application::create($this->dir), $debug)->source());
        $this->assertSame([], $debug->getDeprecations());
    }

    public function test_defaults(): void
    {
        $config = MediaConfig::fromApp(Application::create($this->dir));

        $this->assertSame($this->dir . '/public/media', $config->dir);
        $this->assertSame('/media', $config->url);
        $this->assertSame('json', $config->storage);
        $this->assertSame(20 * 1024 * 1024, $config->maxBytes);
        $this->assertSame(30_000_000, $config->maxPixels);
        $this->assertFalse($config->warmOnUpload);
        $this->assertFalse($config->allowSvg);
        $this->assertNull($config->widths);
        $this->assertSame(75, $config->quality);
        $this->assertSame($this->dir . '/storage/private-media', $config->privateDir);
        $this->assertSame('/files', $config->privateUrl);
        $this->assertSame($this->dir . '/storage/media-versions', $config->versionsDir);
        $this->assertSame($this->dir . '/storage/media-versions', $config->toArray()['versions_dir']);
    }

    public function test_private_dir_under_public_is_refused_naming_the_source(): void
    {
        $this->write('media.php', ['private_dir' => $this->dir . '/public/secret']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/media.php');
        MediaConfig::fromApp(Application::create($this->dir));
    }

    public function test_private_dir_inside_the_media_dir_is_refused_naming_the_fallback(): void
    {
        $this->write('folio.php', ['assets' => ['dir' => $this->dir . '/web/media', 'private_dir' => $this->dir . '/web/media/private']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/folio.php assets');
        MediaConfig::fromApp(Application::create($this->dir));
    }

    public function test_versions_dir_under_public_is_refused_naming_the_source(): void
    {
        $this->write('media.php', ['versions_dir' => $this->dir . '/public/old']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/media.php versions_dir');
        MediaConfig::fromApp(Application::create($this->dir));
    }

    public function test_versions_dir_inside_the_media_dir_is_refused(): void
    {
        $this->write('media.php', ['dir' => $this->dir . '/web/media', 'versions_dir' => $this->dir . '/web/media/old']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('versions_dir');
        MediaConfig::fromApp(Application::create($this->dir));
    }
}
