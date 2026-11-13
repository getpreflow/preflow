<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\TempWorkingDirectory;
use Preflow\Core\Application;
use Preflow\Folio\FolioServiceProvider;
use Preflow\Routing\Router;

final class FolioServiceProviderTest extends TestCase
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
        $this->assertTrue($c->hasProvider(\Preflow\Ai\AiServiceProvider::class));
        $this->assertInstanceOf(\Preflow\Folio\Media\ContentLocaleProvider::class, $c->get(\Preflow\Media\LocaleProvider::class));
        $this->assertSame(['folio', 'media', 'copies'], $c->get(\Preflow\Media\Usage\AssetUsage::class)->sourceNames()); // poster use and copies asked last

        // One ImageTag, Folio's: legacy upload paths resolve under {prefix}/_uploads.
        $c->instance(\Preflow\Data\DataManager::class, new \Preflow\Data\DataManager(drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver(sys_get_temp_dir() . '/folio_order_' . bin2hex(random_bytes(4)))], defaultDriver: 'json'));
        $tag = $c->get(\Preflow\Media\ImageTag::class);
        $this->assertSame($tag, $c->get(\Preflow\Media\ImageTag::class));
        $legacy = '2024/05/' . str_repeat('a', 32) . '.pdf';
        $this->assertSame('/folio/_uploads/' . $legacy, $tag->url($legacy));
    }

    public function test_the_old_asset_names_are_no_services_any_more(): void
    {
        $app = Application::create(['app' => ['debug' => true]]);
        $app->registerProvider(new FolioServiceProvider());
        $c = $app->container();

        foreach (['AssetLibrary', 'ImageTag', 'ImageWidths'] as $name) {
            $this->assertFalse($c->has("Preflow\\Folio\\Assets\\{$name}"), $name);
        }
    }

    /** @param array<string, mixed> $folio */
    /** @param array<string, string> $files more files of the app, by path */
    private function appWith(array $folio, array $files = []): array
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
        // One project extension, so the extension cache has something to hold (it is not written for none).
        $w('app/Folio/CacheNotes/Extension.php', "<?php\nnamespace App\\Folio\\CacheNotes;\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioExtension(name: 'cache_notes', label: 'Cache notes')]\nfinal class Extension extends \\Preflow\\Folio\\Extension\\Extension {}\n");
        if (!class_exists(\App\Folio\CacheNotes\Extension::class, false)) {
            require $dir . '/app/Folio/CacheNotes/Extension.php';
        }
        foreach ($files as $rel => $content) {
            $w($rel, $content);
        }
        $this->appDir = $dir;
        $app = Application::create($dir);
        $app->boot();

        return [$app, $dir];
    }

    /** The temp app appWith() made last, so a test whose boot throws can still remove it. */
    private ?string $appDir = null;

    /** @return \Throwable|null what booting with this publishing.revisions threw */
    private function bootWithRevisions(mixed $revisions): ?\Throwable
    {
        try {
            $this->appWith([], ['config/publishing.php' => '<?php return ' . var_export(['revisions' => $revisions], true) . ";\n"]);

            return null;
        } catch (\Throwable $e) {
            return $e;
        } finally {
            if ($this->appDir !== null) {
                $this->rrmdir($this->appDir);
                $this->appDir = null;
            }
        }
    }

    public function test_negative_revisions_is_a_boot_error(): void
    {
        foreach ([-1, '-3'] as $revisions) {
            $e = $this->bootWithRevisions($revisions);
            $this->assertInstanceOf(\InvalidArgumentException::class, $e, var_export($revisions, true));
            $this->assertSame('publishing.revisions must be 0 (keep all) or more.', $e->getMessage());
        }
    }

    public function test_revisions_must_be_a_whole_number_or_null(): void
    {
        foreach (['abc', '', 2.5, 20.0, true, false, ['x'], '1e2', ' 5'] as $revisions) {
            $e = $this->bootWithRevisions($revisions);
            $this->assertInstanceOf(\InvalidArgumentException::class, $e, var_export($revisions, true));
            $this->assertSame('publishing.revisions must be 0 (keep all) or more.', $e->getMessage());
        }
        $this->assertSame(15, $this->publishTimes(['revisions' => '15'], 17));
    }

    public function test_the_old_folio_key_is_no_longer_read(): void
    {
        [$app, $dir] = $this->appWith(['publishing' => ['revisions' => 3]]);
        $this->assertSame(\Preflow\Publishing\PublishingConfig::DEFAULT_REVISIONS, $app->container()->get(\Preflow\Publishing\PublishingConfig::class)->revisions);
        $this->rrmdir($dir);
    }

    /** @param array<string, mixed> $publishing config/publishing.php */
    private function publishTimes(array $publishing, int $times): int
    {
        [$app, $dir] = $this->appWith([], $publishing === [] ? [] : ['config/publishing.php' => '<?php return ' . var_export($publishing, true) . ";\n"]);
        $publisher = $app->container()->get(\Preflow\Publishing\Publisher::class);
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
        $this->assertSame(25, $this->publishTimes(['revisions' => null], 25));
        $this->assertSame(25, $this->publishTimes(['revisions' => 0], 25));
        $this->assertSame(20, $this->publishTimes([], 25));
    }

    /** @return list<string> the folio-* files under a directory (recursive), as paths relative to it */
    private function folioCacheFiles(string $dir): array
    {
        $found = [];
        foreach (glob($dir . '/folio-*') ?: [] as $file) {
            $found[] = basename($file);
        }
        sort($found);

        return $found;
    }

    private function renderPages(\Preflow\Core\Application $app): void
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $this->assertSame(200, $app->handle($factory->createServerRequest('GET', '/folio'))->getStatusCode());
        $app->handle($factory->createServerRequest('GET', '/'));
    }

    public function test_cache_path_moves_folio_caches(): void
    {
        $fc = sys_get_temp_dir() . '/folio_fc_' . bin2hex(random_bytes(5));
        [$app, $dir] = $this->appWith(['cache_path' => $fc]);
        $this->renderPages($app);

        $this->assertFileExists($fc . '/folio-extensions.cache');
        $this->assertFileDoesNotExist($fc . '/blocks.cache', "preflow/blocks' cache follows config/blocks.php");
        $this->assertFileExists($dir . '/storage/cache/blocks.cache');
        $this->assertSame([], $this->folioCacheFiles($dir . '/storage/cache'));

        $paths = [];
        foreach ($app->container()->get(\Preflow\Core\Cache\CacheRegistry::class)->all() as $source) {
            if ($source instanceof \Preflow\Core\Cache\FileCache) {
                $paths[$source->key()] = (new \ReflectionProperty($source, 'file'))->getValue($source);
            }
        }
        $this->assertSame($fc . '/folio-extensions.cache', $paths['folio.extensions']);
        $this->assertSame($dir . '/storage/cache/blocks.cache', $paths['blocks']);

        $this->rrmdir($fc);
        $this->rrmdir($dir);
    }

    public function test_relative_cache_path_is_from_project_root(): void
    {
        [$app, $dir] = $this->appWith(['cache_path' => 'var/folio/']);
        $this->renderPages($app);

        $this->assertFileExists($dir . '/var/folio/folio-extensions.cache');
        $this->assertFileDoesNotExist($dir . '/var/folio/blocks.cache');
        $this->assertSame([], $this->folioCacheFiles($dir . '/storage/cache'));
        $this->rrmdir($dir);
    }

    public function test_default_cache_path_unchanged(): void
    {
        [$app, $dir] = $this->appWith([]);
        $this->renderPages($app);

        $this->assertFileExists($dir . '/storage/cache/blocks.cache');
        $this->assertFileExists($dir . '/storage/cache/folio-extensions.cache');
        $this->rrmdir($dir);
    }

    public function test_internal_renders_keep_their_content_locale_to_themselves(): void
    {
        [$app, $dir] = $this->appWith([]);
        $locale = $app->container()->get(\Preflow\Folio\Content\ContentLocale::class);
        $request = (new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', '/');

        $app->container()->get(\Preflow\View\Geo\RenderScopes::class)->run($request, function () use ($locale): \Psr\Http\Message\ResponseInterface {
            $locale->set('en');

            return new \Nyholm\Psr7\Response(200);
        });

        // The request never chose a content locale, and the render's choice didn't stay.
        $this->assertNull((new \ReflectionProperty($locale, 'locale'))->getValue($locale));
        $this->rrmdir($dir);
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

    /** @return array<string, mixed> the sections by name */
    private function navSections(array $folio, bool $signedIn = true): array
    {
        $base = sys_get_temp_dir() . '/folio_navcfg_' . bin2hex(random_bytes(5));
        mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/folio.php', '<?php return ' . var_export($folio, true) . ';');
        try {
            $app = Application::create($base);
            $app->setRouter(new Router(pagesDir: null, controllers: []));
            $app->registerProvider(new FolioServiceProvider());
            $app->boot();
            if ($signedIn) {
                $auth = new \Preflow\Auth\AuthManager([], 'web');
                $auth->setUser(new \Preflow\Auth\ArrayUser('u1', 'u@site.test', ''), 'web');
                $app->container()->singleton(\Preflow\Auth\AuthManager::class, static fn () => $auth);
                $app->container()->singleton(\Preflow\Data\DataManager::class, static fn () => new \Preflow\Data\DataManager(['default' => new \Preflow\Data\Driver\SqliteDriver(new \PDO('sqlite::memory:'))]));
            }
            $nav = $app->container()->get(\Preflow\Folio\Http\AdminNav::class);
            $nav->setCurrentPath('/folio/profile');
            $out = [];
            foreach ($nav->sections() as $section) {
                $out[$section['name']] = $section;
            }
            return $out;
        } finally {
            @unlink($base . '/config/folio.php');
            @rmdir($base . '/config');
            @rmdir($base);
        }
    }

    public function test_a_malformed_nav_collapsed_default_is_ignored(): void
    {
        $sections = $this->navSections(['nav' => ['collapsed' => 'settings']]);

        $this->assertArrayHasKey('settings', $sections);
        $this->assertTrue($sections['settings']['open']);
    }

    public function test_nav_collapsed_defaults_to_library_and_settings(): void
    {
        $sections = $this->navSections([]);

        $this->assertFalse($sections['settings']['open']);
        $this->assertFalse($sections['library']['open']);
    }

    public function test_without_a_signed_in_user_the_collapsed_defaults_do_not_apply(): void
    {
        foreach ($this->navSections([], signedIn: false) as $section) {
            $this->assertTrue($section['open'], $section['name']);
        }
    }

    public function test_nav_collapsed_can_be_set_by_the_site(): void
    {
        $sections = $this->navSections(['nav' => ['collapsed' => ['settings']]]);

        $this->assertFalse($sections['settings']['open']);
        $this->assertTrue($sections['library']['open']);
    }

    public function test_a_list_with_a_non_string_is_ignored_as_a_whole(): void
    {
        $sections = $this->navSections(['nav' => ['collapsed' => ['settings', 7]]]);

        $this->assertTrue($sections['settings']['open']);
    }

    public function test_folio_config_is_read_once(): void
    {
        $base = sys_get_temp_dir() . '/folio_cfgonce_' . bin2hex(random_bytes(5));
        mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/folio.php', '<?php return ' . var_export(['password_min' => 12], true) . ';');
        try {
            $app = Application::create($base);
            $app->setRouter(new Router(pagesDir: null, controllers: []));
            $provider = new FolioServiceProvider();
            $app->registerProvider($provider);
            $app->boot();

            $this->assertSame(1, $provider->folioConfigReads);
        } finally {
            @unlink($base . '/config/folio.php');
            @rmdir($base . '/config');
            @rmdir($base);
        }
    }
}
