<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\FolioServiceProvider;
use Preflow\Routing\Router;

final class FolioServiceProviderTest extends TestCase
{
    public function test_boot_appends_admin_and_frontend_routes(): void
    {
        $app = Application::create(['app' => ['debug' => true]]);

        // A real router whose collection we can inspect.
        $router = new Router(pagesDir: null, controllers: []);
        $app->setRouter($router);
        $app->setActionDispatcher(fn ($route, $req) => new \Nyholm\Psr7\Response(200));
        $app->setComponentRenderer(fn ($route, $req) => new \Nyholm\Psr7\Response(200));

        $provider = new FolioServiceProvider();
        $app->registerProvider($provider);
        $app->boot();

        $patterns = array_map(
            fn ($e) => $e->method . ' ' . $e->pattern,
            $router->getCollection()->all(),
        );

        $this->assertContains('GET /folio', $patterns);
        $this->assertContains('POST /folio/{type}', $patterns);
        $this->assertContains('GET /{...path}', $patterns); // frontend catch-all

        // Frontend catch-all must be the LAST entry (lowest priority).
        $last = end($patterns);
        $this->assertSame('GET /{...path}', $last);
    }

    /** Without Composer metadata listing Folio (as here), the provider still registers its texts. */
    public function test_folio_texts_are_registered_without_composer_metadata(): void
    {
        $app = Application::create(['app' => ['debug' => true]]);
        $app->setRouter(new Router(pagesDir: null, controllers: []));
        $app->registerProvider(new FolioServiceProvider());
        $app->boot();

        $translator = $app->container()->get(\Preflow\I18n\Translator::class);
        $this->assertSame('admin', $translator->scope('folio'));
        $this->assertSame('Your account for {site}', $translator->get('folio.mail.invite.subject', [], 'en'));
    }

    /** @return iterable<string, array{list<class-string>}> */
    public static function providerOrders(): iterable
    {
        yield 'media first' => [[\Preflow\Media\MediaServiceProvider::class, FolioServiceProvider::class]];
        yield 'folio first' => [[FolioServiceProvider::class, \Preflow\Media\MediaServiceProvider::class]];
        yield 'folio only' => [[FolioServiceProvider::class]];
    }

    /** @param list<class-string> $providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providerOrders')]
    public function test_media_is_registered_once_and_folio_binds_its_hooks(array $providers): void
    {
        $app = Application::create(['app' => ['debug' => true]]);
        foreach ($providers as $class) {
            $app->registerProvider(new $class());
        }
        $c = $app->container();

        $this->assertTrue($c->hasProvider(\Preflow\Media\MediaServiceProvider::class));
        $this->assertInstanceOf(\Preflow\Folio\Media\ContentLocaleProvider::class, $c->get(\Preflow\Media\LocaleProvider::class));
        $this->assertSame(['folio', 'media'], $c->get(\Preflow\Media\Usage\AssetUsage::class)->sourceNames()); // poster use asked last

        // One ImageTag, Folio's: legacy upload paths resolve under {prefix}/_uploads.
        $c->instance(\Preflow\Data\DataManager::class, new \Preflow\Data\DataManager(drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver(sys_get_temp_dir() . '/folio_order_' . bin2hex(random_bytes(4)))], defaultDriver: 'json'));
        $tag = $c->get(\Preflow\Media\ImageTag::class);
        $this->assertSame($tag, $c->get(\Preflow\Media\ImageTag::class));
        $this->assertSame($tag, $c->get('Preflow\\Folio\\Assets\\ImageTag'));
        $legacy = '2024/05/' . str_repeat('a', 32) . '.pdf';
        $this->assertSame('/folio/_uploads/' . $legacy, $tag->url($legacy));
    }

    public function test_old_class_names_resolve_to_the_media_services(): void
    {
        $app = Application::create(['app' => ['debug' => true]]);
        $app->registerProvider(new FolioServiceProvider());
        $c = $app->container();
        $c->instance(\Preflow\Data\DataManager::class, new \Preflow\Data\DataManager(drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver(sys_get_temp_dir() . '/folio_alias_' . bin2hex(random_bytes(4)))], defaultDriver: 'json'));

        $this->assertSame($c->get(\Preflow\Media\MediaLibrary::class), $c->get('Preflow\\Folio\\Assets\\AssetLibrary'));
        $this->assertSame($c->get(\Preflow\Media\ImageTag::class), $c->get('Preflow\\Folio\\Assets\\ImageTag'));
        $this->assertSame($c->get(\Preflow\Media\Image\ImageWidths::class), $c->get('Preflow\\Folio\\Assets\\ImageWidths'));
    }
}
