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

    /** A site or package that set its own TranslationOverrides keeps them: Folio only fills the empty layer. */
    public function test_an_overrides_binding_of_the_site_is_kept(): void
    {
        $own = new class implements \Preflow\I18n\TranslationOverrides {
            public function get(string $locale, string $key): ?string
            {
                return $key === 'folio.common.save' ? 'Keep it' : null;
            }
        };
        foreach (['site first' => true, 'folio first' => false] as $order => $siteFirst) {
            $app = Application::create(['app' => ['debug' => true]]);
            $app->setRouter(new Router(pagesDir: null, controllers: []));
            $site = new class ($own) extends \Preflow\Core\Container\ServiceProvider {
                public function __construct(private readonly \Preflow\I18n\TranslationOverrides $own) {}
                public function register(\Preflow\Core\Container\Container $container): void {}
                public function boot(\Preflow\Core\Container\Container $container): void
                {
                    $container->get(\Preflow\I18n\Translator::class)->setOverrides($this->own);
                }
            };
            if ($siteFirst) {
                $app->registerProvider($site);
            }
            $app->registerProvider(new FolioServiceProvider());
            if (!$siteFirst) {
                $app->registerProvider($site);
            }
            $app->boot();

            $translator = $app->container()->get(\Preflow\I18n\Translator::class);
            $this->assertSame($own, $translator->overrides(), $order);
            $translator->useOverrides(true);
            $this->assertSame('Keep it', $translator->get('folio.common.save'), $order);
        }
    }

    public function test_without_an_overrides_binding_folio_brings_its_own(): void
    {
        $app = Application::create(['app' => ['debug' => true]]);
        $app->setRouter(new Router(pagesDir: null, controllers: []));
        $app->registerProvider(new FolioServiceProvider());
        $app->boot();

        $overrides = $app->container()->get(\Preflow\I18n\Translator::class)->overrides();
        $this->assertNotInstanceOf(\Preflow\I18n\NoTranslationOverrides::class, $overrides);
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
        $this->assertSame(['folio', 'media', 'copies'], $c->get(\Preflow\Media\Usage\AssetUsage::class)->sourceNames()); // poster use and copies asked last

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

    /** @param array<string, mixed> $folio */
    private function appWith(array $folio): array
    {
        $dir = sys_get_temp_dir() . '/folio_sp_' . bin2hex(random_bytes(5));
        $w = static function (string $rel, string $content) use ($dir): void {
            @mkdir(dirname($dir . '/' . $rel), 0777, true);
            file_put_contents($dir . '/' . $rel, $content);
        };
        $w('config/app.php', "<?php\nreturn ['name' => 'T', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-sp-test-key'];\n");
        $w('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', '<?php return ' . var_export($folio, true) . ";\n");
        $w('config/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
        ]));
        $app = Application::create($dir);
        $app->boot();

        return [$app, $dir];
    }

    private function publishTimes(array $folio, int $times): int
    {
        [$app, $dir] = $this->appWith($folio);
        $publisher = $app->container()->get(\Preflow\Folio\Publishing\Publisher::class);
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $def = $dm->getTypeRegistry()->get('page');
        $record = \Preflow\Data\DynamicRecord::fromArray($def, ['uuid' => 'r1', 'title' => 'T', 'slug' => 't']);
        for ($i = 1; $i <= $times; $i++) {
            $record->set('title', 'T' . $i);
            $dm->saveType($record);
            $publisher->publish($record, null);
        }
        $count = count($publisher->revisions('page', 'r1'));
        $this->rrmdir($dir);

        return $count;
    }

    public function test_revisions_null_in_config_means_keep_all(): void
    {
        $this->assertSame(25, $this->publishTimes(['publishing' => ['revisions' => null]], 25));
        $this->assertSame(25, $this->publishTimes(['publishing' => ['revisions' => 0]], 25));
        $this->assertSame(20, $this->publishTimes([], 25));
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            is_dir("$dir/$e") ? $this->rrmdir("$dir/$e") : @unlink("$dir/$e");
        }
        @rmdir($dir);
    }
}
