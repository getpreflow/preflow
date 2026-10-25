<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * A full app with an extension screen that declares one CSS and one JS file (the Shop
 * fixture's shop.css and shop.js): the page links them, the files are served with their
 * headers under the strict CSP, and the page carries no inline script or style.
 */
final class ScreenAssetsAppTest extends TestCase
{
    use PublishingScaffold;

    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'session' => ['cookie' => 'folio_screen_assets_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => ['*']]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_screen_assets_render_are_served_and_stay_csp_clean(): void
    {
        $fixtures = dirname(__DIR__) . '/Fixtures/extensions/Shop/assets';
        $this->write('app/Folio/Shop/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Shop;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'shop', label: 'Shop')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Shop/Screens/Orders.php', <<<'PHP'
            <?php
            namespace App\Folio\Shop\Screens;
            #[\Preflow\Folio\Extension\Attributes\FolioScreen('orders', 'Orders', permission: 'shop.view', assets: ['shop.css', 'shop.js'])]
            final class Orders
            {
                #[\Preflow\Routing\Attributes\Get('/')]
                public function index(): \Preflow\Folio\Extension\Screen\ScreenView
                {
                    return new \Preflow\Folio\Extension\Screen\ScreenView('@shop/orders.twig');
                }
            }
            PHP);
        $this->write('app/Folio/Shop/templates/orders.twig', "{% extends '@folio/admin/_layout.twig' %}{% block content %}<p class=\"shop-orders\">Orders</p>{% endblock %}");
        foreach (['shop.css', 'shop.js'] as $file) {
            $this->write('app/Folio/Shop/assets/' . $file, (string) file_get_contents($fixtures . '/' . $file));
        }
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => ['shop' => new \stdClass()]]));

        $app = Application::create($this->dir);
        $app->boot();
        $f = new Psr17Factory();
        $page = (string) $app->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $app->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());

        $res = $app->handle($f->createServerRequest('GET', '/folio/orders'));
        $html = (string) $res->getBody();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('class="shop-orders"', $html);

        $this->assertSame(1, preg_match('#<link rel="stylesheet" href="(/folio/_assets/x/shop/shop\.css\?v=[0-9a-f]{12})">#', $html, $css));
        $this->assertSame(1, preg_match('#<script src="(/folio/_assets/x/shop/shop\.js\?v=[0-9a-f]{12})" defer nonce="([^"]+)"></script>#', $html, $js));

        $served = $app->handle($f->createServerRequest('GET', $css[1]));
        $this->assertSame(200, $served->getStatusCode());
        $this->assertSame((string) file_get_contents($fixtures . '/shop.css'), (string) $served->getBody());
        $this->assertSame('text/css; charset=UTF-8', $served->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('immutable', $served->getHeaderLine('Cache-Control'));

        $script = $app->handle($f->createServerRequest('GET', $js[1]));
        $this->assertSame(200, $script->getStatusCode());
        $this->assertSame((string) file_get_contents($fixtures . '/shop.js'), (string) $script->getBody());

        // Strict CSP on the page, carrying the nonce the script tag uses; nothing inline.
        $csp = $this->csp($res);
        $this->assertNotSame('', $csp, 'the page sends a Content-Security-Policy');
        $this->assertStringContainsString("'nonce-{$js[2]}'", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertSame(0, preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html), 'inline event handler');
        $this->assertSame(0, preg_match('/<[a-z][^>]*\sstyle\s*=/i', $html), 'style attribute');
        preg_match_all('/<style\b[^>]*>/i', $html, $styles);
        foreach ($styles[0] as $tag) {
            $this->assertMatchesRegularExpression('/\bnonce\s*=/i', $tag, "style without nonce: {$tag}");
        }
        preg_match_all('/<script\b[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            $this->assertMatchesRegularExpression('/\b(nonce|src)\s*=/i', $tag, "script without nonce or src: {$tag}");
        }
    }

    private function csp(ResponseInterface $res): string
    {
        return $res->getHeaderLine('Content-Security-Policy') ?: $res->getHeaderLine('Content-Security-Policy-Report-Only');
    }
}
