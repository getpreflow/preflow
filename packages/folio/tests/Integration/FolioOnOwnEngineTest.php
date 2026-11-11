<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

require_once dirname(__DIR__) . '/Fixtures/PhpEngine/PhtmlEngine.php';
require_once dirname(__DIR__) . '/Fixtures/PhpEngine/PhtmlEngineFactory.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Fixtures\PhpEngine\PhtmlEngineFactory;
use Preflow\Folio\View\FolioEngine;
use Preflow\View\TemplateEngineInterface;

/**
 * Folio on an app whose engine is not Twig (app.engine names a TemplateEngineFactory of .phtml
 * templates): the admin and Folio's fallback frontend templates render through Folio's own Twig
 * engine, an app type's own template through the app's engine, with the folio_* functions.
 */
final class FolioOnOwnEngineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_own_engine_' . bin2hex(random_bytes(4));
        $w = function (string $rel, string $content): void {
            $path = $this->dir . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        };
        $w('config/app.php', "<?php\nreturn ['name'=>'Own Engine','debug'=>1,'engine'=>" . var_export(PhtmlEngineFactory::class, true) . ",'key'=>'folio-own-engine-test-key'];\n");
        $w('config/data.php', "<?php\nreturn ['drivers'=>['json'=>['driver'=>'json','path'=>__DIR__.'/../storage/data']],'default'=>'json','models_path'=>__DIR__.'/models'];\n");
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', "<?php\nreturn ['path'=>'/folio'];\n");
        $w('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT), 'roles' => ['admin']],
            ]]],
            'password_hasher' => \Preflow\Auth\NativePasswordHasher::class,
            'session' => ['cookie' => 'folio_own_engine', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $w('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages', 'publishing' => false, 'frontend' => ['route' => '/{slug}'],
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'status' => ['type' => 'string']],
        ]));
        $w('config/models/job.json', (string) json_encode([
            'key' => 'job', 'table' => 'job', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Jobs', 'publishing' => false,
            'frontend' => ['route' => '/jobs/{slug}', 'template' => 'templates/job.phtml'],
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'status' => ['type' => 'string']],
        ]));
        $w('templates/job.phtml', '<html><head></head><body><h1 class="php"><?= $e($record["title"]) ?></h1>'
            . '<p class="pages"><?= count($fn("folio_list", "page")) ?> pages</p>'
            . '<?= $call("folio_alternate_links", $alternates) ?></body></html>');
        $w('app/pages/index.phtml', '<html><head></head><body>HOME</body></html>');
        @mkdir($this->dir . '/storage/data', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function save(Application $app, string $type, array $values): void
    {
        $dm = $app->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => bin2hex(random_bytes(8))] + $values), false);
    }

    #[RunInSeparateProcess]
    public function test_the_admin_renders_through_folio_s_own_twig_engine(): void
    {
        $app = $this->app();
        $f = new Psr17Factory();

        $engine = $app->container()->get(FolioEngine::class);
        $this->assertNotSame($engine->app(), $engine->twig(), 'an engine of its own beside the app\'s');
        $this->assertSame($app->container()->get(TemplateEngineInterface::class), $engine->app(), 'the app keeps its engine');

        $login = $app->handle($f->createServerRequest('GET', '/folio/login'));
        $page = (string) $login->getBody();
        $this->assertSame(200, $login->getStatusCode(), $page);
        $this->assertStringContainsString('name="email"', $page);
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->assertNotEmpty($m[1] ?? '', 'csrf_field() from the app engine\'s functions');

        $signIn = $app->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $m[1], 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame('/folio', $signIn->getHeaderLine('Location'));
        $dashboard = $app->handle($f->createServerRequest('GET', '/folio'));
        $this->assertSame(200, $dashboard->getStatusCode(), (string) $dashboard->getBody());
        $this->assertStringContainsString('folio-sidebar', (string) $dashboard->getBody());
        $list = $app->handle($f->createServerRequest('GET', '/folio/job'));
        $this->assertSame(200, $list->getStatusCode(), (string) $list->getBody());
    }

    #[RunInSeparateProcess]
    public function test_folio_s_fallback_page_and_an_app_template_with_folio_functions(): void
    {
        $app = $this->app();
        $this->save($app, 'page', ['title' => 'About us', 'slug' => 'about', 'status' => 'published']);
        $this->save($app, 'job', ['title' => 'Platform Wrangler', 'slug' => 'wrangler', 'status' => 'published']);
        $f = new Psr17Factory();

        $about = $app->handle($f->createServerRequest('GET', '/about'));
        $this->assertSame(200, $about->getStatusCode(), (string) $about->getBody());
        $this->assertStringContainsString('<h1>About us</h1>', (string) $about->getBody(), '@folio/frontend/page.twig');

        $job = $app->handle($f->createServerRequest('GET', '/jobs/wrangler'));
        $html = (string) $job->getBody();
        $this->assertSame(200, $job->getStatusCode(), $html);
        $this->assertStringContainsString('<h1 class="php">Platform Wrangler</h1>', $html, 'the app\'s .phtml template');
        $this->assertStringContainsString('<p class="pages">1 pages</p>', $html, 'folio_list() on the app engine');

        $home = $app->handle($f->createServerRequest('GET', '/'));
        $this->assertStringContainsString('HOME', (string) $home->getBody(), 'the app\'s own pages are the app engine\'s');
    }
}
