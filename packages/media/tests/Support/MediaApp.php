<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Application;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\ResponseInterface;

/**
 * A booted site without Folio in a temp dir: JSON storage, Twig, config/providers.php
 * listing MediaServiceProvider (plus whatever a test adds). One app per test.
 */
trait MediaApp
{
    private string $dir;

    private ?Application $booted = null;

    /** @param list<class-string> $providers */
    private function scaffoldMediaApp(array $providers = [\Preflow\Media\MediaServiceProvider::class]): void
    {
        putenv('APP_DEBUG=1');
        $this->dir = sys_get_temp_dir() . '/media_app_' . bin2hex(random_bytes(4));
        $this->writeFile('config/app.php', "<?php\nreturn ['name' => 'Media', 'debug' => 1, 'engine' => 'twig', 'key' => 'media-integration-test-key'];\n");
        $this->writeFile('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json'];\n");
        $this->providers($providers);
    }

    /** @param list<class-string> $providers */
    private function providers(array $providers): void
    {
        $this->writeFile('config/providers.php', "<?php\nreturn [" . implode(', ', array_map(static fn (string $c): string => '\\' . ltrim($c, '\\') . '::class', $providers)) . "];\n");
    }

    private function removeMediaApp(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function mediaApp(): Application
    {
        if ($this->booted === null) {
            $this->booted = Application::create($this->dir);
            $this->booted->boot();
        }

        return $this->booted;
    }

    private function media(): MediaLibrary
    {
        return $this->mediaApp()->container()->get(MediaLibrary::class);
    }

    private function fetch(string $uri): ResponseInterface
    {
        return $this->mediaApp()->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }
}
