<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\TempWorkingDirectory;
use Preflow\Core\Application;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\LazyHtmlSanitizer;
use Preflow\Folio\FolioServiceProvider;
use Preflow\Routing\Router;

final class LazyHtmlSanitizerTest extends TestCase
{
    use TempWorkingDirectory;

    protected function setUp(): void
    {
        $this->enterTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->leaveTempDirectory();
    }

    public function test_booting_folio_builds_no_sanitizer(): void
    {
        LazyHtmlSanitizer::$built = 0;
        $app = Application::create(['app' => ['debug' => true]]);
        $app->setRouter(new Router(pagesDir: null, controllers: []));
        $app->container()->instance(\Preflow\Data\DataManager::class, new \Preflow\Data\DataManager(drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver(getcwd() . '/storage/data')], defaultDriver: 'json'));
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
