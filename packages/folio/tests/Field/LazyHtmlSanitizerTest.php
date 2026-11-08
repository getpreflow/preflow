<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\LazyHtmlSanitizer;
use Preflow\Folio\FolioServiceProvider;
use Preflow\Routing\Router;

final class LazyHtmlSanitizerTest extends TestCase
{
    private string $cwd = '';
    private string $base = '';

    /** Array-config apps take the working directory as base path: give them a temp one, not the repo root. */
    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
        $this->base = sys_get_temp_dir() . '/folio_boot_' . bin2hex(random_bytes(5));
        mkdir($this->base, 0777, true);
        chdir($this->base);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->base);
    }

    public function test_booting_folio_builds_no_sanitizer(): void
    {
        LazyHtmlSanitizer::$built = 0;
        $app = Application::create(['app' => ['debug' => true]]);
        $app->setRouter(new Router(pagesDir: null, controllers: []));
        $app->container()->instance(\Preflow\Data\DataManager::class, new \Preflow\Data\DataManager(drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver(sys_get_temp_dir() . '/folio_lazy_' . bin2hex(random_bytes(4)))], defaultDriver: 'json'));
        $app->registerProvider(new FolioServiceProvider());
        $app->boot();

        $app->container()->get(FieldTypeRegistry::class);
        $this->assertSame(0, LazyHtmlSanitizer::$built);
    }

    public function test_rich_text_is_still_sanitized(): void
    {
        LazyHtmlSanitizer::$built = 0;
        $s = LazyHtmlSanitizer::standard();
        $this->assertSame(0, LazyHtmlSanitizer::$built);
        $this->assertSame('<p>hi</p>', $s->sanitize('<p onclick="x()">hi<script>alert(1)</script></p>'));
        $s->sanitize('<b>x</b>');
        $this->assertSame(1, LazyHtmlSanitizer::$built);
    }
}
