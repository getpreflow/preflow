<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Components\ComponentMap;
use Preflow\Core\Application;
use Preflow\Core\Boot\ManifestBuilder;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Console\ExtensionsApplyCommand;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionsFile;
use Preflow\Folio\Publishing\Publisher;
use Preflow\I18n\TranslationRegistry;

/**
 * The boot manifest on a realistic site, in web mode: Folio pages with component and data-only
 * blocks (one block a component of the site's app/Components), interface strings in two
 * languages, SEO and GEO on, the rbac extension with its models and a site "extends" file.
 * A web boot with the manifest answers exactly as one without; a toggled extension is seen
 * by the next request without `preflow optimize`.
 */
final class BootManifestAppTest extends TestCase
{
    private const array PACKAGES = ['folio', 'rbac', 'validation', 'i18n', 'media'];
    private const array PATHS = ['/', '/about', '/de/about', '/de', '/no-such-page', '/robots.txt', '/sitemap.xml', '/llms.txt', '/about.md', '/folio/login'];

    private string $dir = '';
    private string|false $errorLog = false;

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->errorLog);
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    #[RunInSeparateProcess]
    public function test_output_is_identical_with_and_without_manifest(): void
    {
        $this->site();
        $live = $this->snapshot($this->app(console: false));
        $this->assertFalse($this->app(console: false)->bootManifest()->active());
        $this->assertStringContainsString('<p class="greeting">Hi Ada</p>', $live['GET /about'][1], 'the site component block renders');
        $this->assertStringContainsString('<blockquote>', $live['GET /about'][1], 'a nested data-only block renders');
        $this->assertStringContainsString('Willkommen', $live['GET /de/about'][1], 'interface strings in German');
        $this->assertStringContainsString('og:title', $live['GET /about'][1], 'SEO head tags');
        $this->assertSame(200, $live['GET /about.md'][0], 'GEO Markdown view');

        $result = (new ManifestBuilder($this->dir))->write();
        $this->assertTrue($result['written']);
        foreach (['core.components', 'i18n.translations', 'folio.extensions', 'folio.extension_types', 'folio.type_listings', 'blocks', 'folio.interface_locales'] as $catalog) {
            $this->assertContains($catalog, $result['catalogs']);
        }

        $app = $this->app(console: false);
        $this->assertTrue($app->bootManifest()->active());
        $this->assertSame($live, $this->snapshot($app), 'the same responses, config and catalogs');
    }

    #[RunInSeparateProcess]
    public function test_the_catalogs_come_from_the_manifest(): void
    {
        $this->site();
        (new ManifestBuilder($this->dir))->write();
        // Gone after the build: a manifest boot doesn't read them (installed.json is only stat-ed).
        $installed = $this->dir . '/vendor/composer/installed.json';
        $mtime = filemtime($installed);
        file_put_contents($installed, str_repeat(' ', filesize($installed))); // same size, mtime and inode: unreadable content
        touch($installed, $mtime);
        rename($this->dir . '/config/folio.php', $this->dir . '/config/folio.moved');
        rename($this->dir . '/config/models/rbac_role.json', $this->dir . '/config/rbac_role.moved');

        $app = $this->app(console: false);

        $this->assertTrue($app->bootManifest()->active());
        $this->assertSame('/folio', $app->configFile('folio')['path'] ?? null, 'config/folio.php from the manifest');
        $this->assertContains('note', array_keys($app->container()->get(DataManager::class)->getTypeRegistry()->get('rbac_role')->fields), 'the site "extends" file, merged');
        $this->assertContains('greeting', array_keys($app->container()->get(BlockRegistry::class)->all()));
        $this->assertContains('prose', array_keys($app->container()->get(BlockRegistry::class)->all()), 'from config/folio.php');
        $this->assertArrayHasKey('folio', $app->container()->get(TranslationRegistry::class)->groups(), 'from installed.json');
        $this->assertSame(['rbac'], array_map(static fn ($d): string => $d->name, $app->container()->get(ExtensionRegistry::class)->enabled()));
        $this->assertContains('rbac_role', array_map(static fn ($l): string => $l->key, $app->container()->get(TypeCatalog::class)->all()));
        $this->assertSame(200, $app->handle((new Psr17Factory())->createServerRequest('GET', '/about'))->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_a_changed_extensions_json_bypasses_the_manifest(): void
    {
        $this->site();
        (new ManifestBuilder($this->dir))->write();
        $types = fn (Application $app): array => array_map(static fn ($l): string => $l->key, $app->container()->get(TypeCatalog::class)->all());
        $this->assertNotContains('staff', $types($this->app(console: false)));

        // An editor switches on rbac's opt-in staff model (Settings → Extensions writes the file).
        $app = $this->app(console: false);
        $rbac = $app->container()->get(ExtensionRegistry::class)->get('rbac');
        (new ExtensionsFile($this->dir . '/config/folio/extensions.json', $this->dir . '/config/models'))->enable($rbac, 'model:staff');

        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php', 'the writer removes the manifest');
        $next = $this->app(console: false);
        $this->assertFalse($next->bootManifest()->active());
        $this->assertContains('staff', $types($next), 'the next request has it, without preflow optimize');

        // A manifest built since is used again, with the extension as it is now.
        (new ManifestBuilder($this->dir))->write();
        $again = $this->app(console: false);
        $this->assertTrue($again->bootManifest()->active());
        $this->assertContains('staff', $types($again));

        // Rewritten some other way (an editor, a deploy without optimize): the per-request check catches it.
        $file = $this->dir . '/config/folio/extensions.json';
        file_put_contents($file, '{"enabled": {"rbac": {"disabled_parts": []}}}');
        touch($file, time() + 10);
        $after = $this->app(console: false);
        $this->assertFalse($after->bootManifest()->active());
        $this->assertNotContains('staff', $types($after));
    }

    #[RunInSeparateProcess]
    public function test_cli_ignores_the_manifest(): void
    {
        $this->site();
        (new ManifestBuilder($this->dir))->write();

        $this->assertFalse($this->app(console: true)->bootManifest()->active(), 'commands and cron jobs boot live');
    }

    private const string FRONTEND = 'Preflow\\Folio\\Http\\FrontendController@show';
    private const string HEX = '0123456789abcdef0123456789abcdef';

    #[RunInSeparateProcess]
    public function test_public_routes_come_compiled_from_the_manifest(): void
    {
        $this->site();
        $live = $this->app(console: false);
        $this->assertFalse($live->bootManifest()->active());
        $this->assertSame(['app', 'media', 'folio.frontend'], $live->container()->get(\Preflow\Core\Routing\RouterInterface::class)->liveSets(), 'without one, all three are compiled');

        $result = (new ManifestBuilder($this->dir))->write();
        $this->assertSame(['app', 'media', 'folio.frontend'], $result['routes']);
        $this->assertContains('folio.frontend_routes', $result['catalogs']);

        // Marked in the manifest: what a manifest boot uses is the stored value, not a fresh compile.
        $data = include $this->dir . '/bootstrap/cache/boot.php';
        $data['catalogs']['folio.frontend_routes'][0]['regex'] = '#^/(?P<slug>[^/]+)-x$#';
        \Preflow\Core\Boot\ManifestFile::write($this->dir, $data);

        $app = $this->app(console: false);
        $this->assertTrue($app->bootManifest()->active());
        $router = $app->container()->get(\Preflow\Core\Routing\RouterInterface::class);
        $this->assertSame(200, $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/'))->getStatusCode());
        $this->assertSame(200, $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/about-x'))->getStatusCode(), 'the content routes are the manifest\'s');
        $this->assertSame(404, $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/about'))->getStatusCode());
        $this->assertSame([], $router->liveSets(), 'no route set compiled: app, media and the frontend catch-all come from the manifest');
    }

    #[RunInSeparateProcess]
    public function test_admin_routes_compile_on_the_first_admin_request(): void
    {
        $this->site();
        (new ManifestBuilder($this->dir))->write();
        $app = $this->app(console: false);
        $router = $app->container()->get(\Preflow\Core\Routing\RouterInterface::class);
        $patterns = static fn (): array => array_map(static fn ($e): string => $e->method . ' ' . $e->pattern, $router->getCollection()->all());

        $this->assertSame(200, $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/about'))->getStatusCode());
        $public = $patterns();
        $this->assertSame([], preg_grep('#^\S+ /folio#', $public), 'a public request registers no admin route');
        $this->assertSame('GET /{...path}', end($public));

        $this->assertSame(200, $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/folio'))->getStatusCode());
        $all = $patterns();
        $this->assertContains('GET /folio', $all);
        $this->assertContains('GET /folio/{type}/{id}/edit', $all);
        $this->assertSame('GET /{...path}', end($all), 'the admin routes go in front of the frontend catch-all');
        $this->assertSame([], $router->liveSets(), 'the public sets still from the manifest; the admin\'s are compiled live, outside them');
        $this->assertNotContains('folio.admin', array_keys((include $this->dir . '/bootstrap/cache/boot.php')['routes']));

        // A second admin request adds nothing.
        $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/folio/page'));
        $this->assertSame($all, $patterns());
    }

    #[RunInSeparateProcess]
    public function test_routing_matches_identically_with_and_without_cache(): void
    {
        $this->site();
        $live = $this->app(console: false);
        $live->container()->get(\Preflow\Folio\Redirects\RedirectEntries::class)->save(['path' => '/old-about', 'locale' => '', 'target_type' => 'page', 'target_id' => 'about']);

        // What the router matches (the path as it reaches the router: the locale prefix is gone by then).
        $routes = [
            'GET /' => [self::FRONTEND, ['path' => '']],
            'GET /about' => [self::FRONTEND, ['path' => 'about']],
            'GET /about/' => [self::FRONTEND, ['path' => 'about']],
            'HEAD /about' => [self::FRONTEND, ['path' => 'about']],
            'GET /a/b/c' => [self::FRONTEND, ['path' => 'a/b/c']],
            'GET /media/2026/x.jpg' => ['Preflow\\Media\\Http\\VariantController@serve', ['path' => '2026/x.jpg']],
            'GET /files/' . self::HEX => ['Preflow\\Media\\Http\\PrivateFileController@serve', ['id' => self::HEX]],
            'GET /files/' . self::HEX . '/v/3' => ['Preflow\\Media\\Http\\PrivateFileController@serveVersion', ['id' => self::HEX, 'n' => '3']],
            'GET /files/not-an-id' => [self::FRONTEND, ['path' => 'files/not-an-id']],
            'GET /files/' . self::HEX . '/v/0' => [self::FRONTEND, ['path' => 'files/' . self::HEX . '/v/0']],
            'POST /about' => 404,
            'GET /blog/2026/hello' => ['blog/[...path].twig', ['path' => '2026/hello']],
            'GET /hooks/ping' => ['App\\Controllers\\HookController@show', ['name' => 'ping']],
            'POST /hooks' => ['App\\Controllers\\HookController@receive', []],
            'GET /hooks' => [self::FRONTEND, ['path' => 'hooks']],
        ];
        // What a request gets: the locale prefix, a redirect (also behind the prefix), 404 and the admin.
        $requests = [
            '/' => [200, ''],
            '/about' => [200, ''],
            '/de/about' => [200, ''],
            '/de' => [200, ''],
            '/old-about' => [301, '/about'],
            '/de/old-about' => [301, '/de/about'],
            '/no-such-page' => [404, ''],
            '/de/no-such-page' => [404, ''],
            '/folio' => [200, ''],
            '/folio/page' => [200, ''],
            '/folio/no-such-type' => [404, ''],
            '/blog/2026/hello' => [200, ''],
            '/hooks/ping' => [200, ''],
        ];
        $url = static fn (Application $app): ?string => $app->container()->get(\Preflow\Folio\Content\FrontendResolver::class)
            ->urlFor($app->container()->get(DataManager::class)->findType('page', 'about'), 'de');

        $before = [$this->routing($this->app(console: false), array_keys($routes)), $this->requests($this->app(console: false), array_keys($requests)), $url($this->app(console: false)), $this->entries($this->app(console: false))];
        (new ManifestBuilder($this->dir))->write();
        $app = $this->app(console: false);
        $this->assertTrue($app->bootManifest()->active());
        $after = [$this->routing($app, array_keys($routes)), $this->requests($this->app(console: false), array_keys($requests)), $url($this->app(console: false)), $this->entries($this->app(console: false))];

        $this->assertSame($routes, $before[0]);
        $this->assertSame($requests, $before[1]);
        $this->assertStringEndsWith('/de/about', (string) $before[2], 'a record\'s URL from its route');
        $entries = $before[3];
        $this->assertSame('GET /{...path}', array_key_last($entries), 'Folio\'s catch-all comes last');
        $this->assertLessThan(array_search('GET /{...path}', array_keys($entries), true), array_search('GET /blog/{...path}', array_keys($entries), true), 'the site\'s catch-all page first');
        $this->assertSame([[\Preflow\Core\Http\JsonBodyMiddleware::class, \Preflow\Core\Http\SecurityHeadersMiddleware::class], true], $entries['POST /hooks'], 'middleware and the CSRF exemption');
        $this->assertSame($before, $after, 'the same with the manifest');
        $this->assertSame([], $app->container()->get(\Preflow\Core\Routing\RouterInterface::class)->liveSets());
    }

    /** @return array<string, array{0: list<string>, 1: bool}> every route in order: "METHOD /pattern" => middleware, CSRF-exempt */
    private function entries(Application $app): array
    {
        $out = [];
        foreach ($app->container()->get(\Preflow\Core\Routing\RouterInterface::class)->getCollection()->all() as $entry) {
            $out[$entry->method . ' ' . $entry->pattern] = [$entry->middleware, $entry->csrfExempt];
        }

        return $out;
    }

    /**
     * @param list<string> $keys "METHOD /path"
     * @return array<string, array{0: string, 1: array<string, string>}|int>
     */
    private function routing(Application $app, array $keys): array
    {
        $router = $app->container()->get(\Preflow\Core\Routing\RouterInterface::class);
        $out = [];
        foreach ($keys as $key) {
            [$method, $path] = explode(' ', $key, 2);
            try {
                $route = $router->match((new Psr17Factory())->createServerRequest($method, 'https://site.test' . $path));
                $out[$key] = [$route->handler, $route->parameters];
            } catch (\Preflow\Core\Exceptions\NotFoundHttpException) {
                $out[$key] = 404;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $paths
     * @return array<string, array{0: int, 1: string}> status and Location
     */
    private function requests(Application $app, array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $response = $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test' . $path));
            $out[$path] = [$response->getStatusCode(), $response->getHeaderLine('Location')];
        }

        return $out;
    }

    /** @return array<string, mixed> what requests get, plus the config and catalogs boot set up */
    private function snapshot(Application $app): array
    {
        $out = [];
        foreach (self::PATHS as $path) {
            $response = $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test' . $path));
            $headers = $response->getHeaders();
            unset($headers['Content-Security-Policy'], $headers['Content-Security-Policy-Report-Only'], $headers['Date']);
            $out['GET ' . $path] = [
                $response->getStatusCode(),
                (string) preg_replace('/nonce-[A-Za-z0-9+\/=_-]+|nonce="[^"]+"|Nonce":"[^"]+"/', 'NONCE', (string) $response->getBody()),
                $headers,
            ];
        }
        $c = $app->container();
        $out['config'] = $app->config()->all();
        $out['folio config'] = $app->configFile('folio');
        $out['components'] = $c->get(ComponentMap::class)->all();
        $out['blocks'] = array_keys($c->get(BlockRegistry::class)->all());
        $out['types'] = array_map(static fn ($l): array => get_object_vars($l), $c->get(TypeCatalog::class)->all());
        $out['extensions'] = $c->get(ExtensionRegistry::class)->toArray();
        $out['translations'] = $c->get(TranslationRegistry::class)->toArray();
        $out['role fields'] = array_keys($c->get(DataManager::class)->getTypeRegistry()->get('rbac_role')->fields);

        return $out;
    }

    private function site(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_manifest_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage/data', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log');
        $dir = $this->dir;
        spl_autoload_register(static function (string $class) use ($dir): void {
            if (str_starts_with($class, 'App\\Components\\')) {
                $file = $dir . '/app/Components/' . str_replace('\\', '/', substr($class, 15)) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
            if (str_starts_with($class, 'App\\Controllers\\')) {
                $file = $dir . '/app/Controllers/' . str_replace('\\', '/', substr($class, 16)) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });

        $this->write('.env', "APP_MANIFEST_TEST=1\n");
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Manifest club', 'debug' => 0, 'engine' => 'twig', 'key' => 'manifest-app-test-key-0123456789', 'url' => 'https://site.test', 'locale' => 'en'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn " . var_export(['path' => '/folio', 'blocks' => ['classes' => [
            \Preflow\Folio\Tests\Fixtures\Blocks\ProseBlock\ProseBlock::class,
            \Preflow\Folio\Tests\Fixtures\Blocks\QuoteBlock::class,
        ]]], true) . ";\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data']],
        ], true) . ";\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true, 'name' => 'Manifest club'];\n");
        $this->write('config/geo.php', "<?php\nreturn ['enabled' => true, 'types' => ['page']];\n");
        $this->write('lang/en/site.php', "<?php\nreturn ['welcome' => 'Welcome'];\n");
        $this->write('lang/de/site.php', "<?php\nreturn ['welcome' => 'Willkommen'];\n");

        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'frontend' => ['route' => '/{slug}', 'home' => 'home'],
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'slug' => ['type' => 'string', 'translatable' => true],
                'sections' => ['type' => 'blocks', 'allowed' => ['greeting', 'prose']],
            ],
        ]));
        // The site adds a field to rbac's role type ("extends").
        $this->write('config/models/rbac_role.json', (string) json_encode(['key' => 'rbac_role', 'extends' => true, 'fields' => ['note' => ['type' => 'string']]]));
        $this->write('resources/folio/frontend/page.twig', '<html><head></head><body><p class="welcome">{{ t("site.welcome") }}</p><h1>{{ record.title }}</h1>{{ folio_blocks(record.sections|default([])) }}</body></html>');

        // A component of the site that is a block (discovered from app/Components).
        $this->write('app/Components/Greeting/Greeting.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Components\Greeting;

            use Preflow\Folio\Block\Block;
            use Preflow\Folio\Block\BlockComponent;
            use Preflow\Folio\Block\BlockField;

            #[Block(key: 'greeting', label: 'Greeting')]
            final class Greeting extends BlockComponent
            {
                #[BlockField(type: 'string', translatable: true)]
                public string $name = '';
            }
            PHP);
        $this->write('app/Components/Greeting/Greeting.twig', '<p class="greeting">Hi {{ name }}</p>');

        // The site's own routes, which must come before Folio's catch-all: a catch-all page and an
        // attribute controller (middleware, a CSRF-exempt POST).
        $this->write('app/pages/blog/[...path].twig', 'BLOG {{ route.path }}');
        $this->write('app/Controllers/HookController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Preflow\Core\Http\Csrf\CsrfExempt;
            use Preflow\Routing\Attributes\Get;
            use Preflow\Routing\Attributes\Middleware;
            use Preflow\Routing\Attributes\Post;
            use Preflow\Routing\Attributes\Route;
            use Psr\Http\Message\ServerRequestInterface;

            #[Route('/hooks', middleware: [\Preflow\Core\Http\JsonBodyMiddleware::class])]
            final class HookController
            {
                #[Get('/{name}')]
                public function show(ServerRequestInterface $request): string
                {
                    return 'hook ' . $request->getAttribute('name');
                }

                #[Post('/')]
                #[CsrfExempt('a signed webhook')]
                #[Middleware(\Preflow\Core\Http\SecurityHeadersMiddleware::class)]
                public function receive(ServerRequestInterface $request): string
                {
                    return 'received';
                }
            }
            PHP);

        $packages = [];
        foreach (self::PACKAGES as $name) {
            $root = dirname(__DIR__, 3) . '/' . $name;
            $packages[] = [...json_decode((string) file_get_contents($root . '/composer.json'), true), 'install-path' => $root];
        }
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": []}}}');

        foreach ([new MigrateCommand(fn () => $this->app(console: true)), new ExtensionsApplyCommand(fn () => $this->app(console: true))] as $command) {
            ob_start();
            try {
                $code = $command->execute([]);
            } finally {
                $out = (string) ob_get_clean();
            }
            $this->assertSame(0, $code, $out);
        }

        $this->publish('home', ['title' => ['en' => 'Home', 'de' => 'Start'], 'slug' => ['en' => 'home', 'de' => 'start']]);
        $this->publish('about', [
            'title' => ['en' => 'About', 'de' => 'Über uns'],
            'slug' => ['en' => 'about', 'de' => 'about'],
            'sections' => [
                ['_uid' => 'g1', '_block' => 'greeting', 'name' => ['en' => 'Ada', 'de' => 'Ada']],
                ['_uid' => 'p1', '_block' => 'prose', 'body' => ['en' => '<p>Board games</p>', 'de' => '<p>Brettspiele</p>'], 'caption' => ['en' => 'Weekly'],
                    'quotes' => [['_uid' => 'q1', '_block' => 'quote', 'text' => ['en' => '<p>Fun</p>']]]],
            ],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function publish(string $id, array $data): void
    {
        $app = $this->app(console: true);
        $dm = $app->container()->get(DataManager::class);
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get('page'), ['uuid' => $id, '_locales' => ['en', 'de']] + $data);
        $dm->saveType($record, false);
        $app->container()->get(Publisher::class)->publish($record, null);
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function app(bool $console): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole($console);

        $app->boot();

        return $app;
    }
}
