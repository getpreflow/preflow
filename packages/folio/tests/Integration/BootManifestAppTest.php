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
        foreach (['core.components', 'i18n.translations', 'folio.extensions', 'folio.extension_types', 'folio.type_listings', 'folio.blocks', 'folio.interface_locales'] as $catalog) {
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
        // Gone after the build: a manifest boot doesn't read them.
        rename($this->dir . '/vendor/composer/installed.json', $this->dir . '/vendor/composer/installed.moved');
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
