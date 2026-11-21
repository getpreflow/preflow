<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Override;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;

/**
 * A full app: the dashboard override returns the same ScreenView a screen returns, and the page
 * is built the same way (layout, prefix, csrf, resolved stylesheet); an override returning a
 * response is passed through untouched.
 */
final class ScreenOverrideAppTest extends TestCase
{
    use ChecksFrameBalance;

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
            'session' => ['cookie' => 'folio_override_test', 'path' => '/', 'secure' => false],
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

    private function login(Application $app, Psr17Factory $f): void
    {
        $page = (string) $app->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $app->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    private function extension(): void
    {
        $this->write('app/Folio/Stats/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Stats;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'stats', label: 'Stats')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Stats/templates/board.twig', "{% extends '@folio/admin/_layout.twig' %}{% block content %}<p class=\"probe\" data-prefix=\"{{ prefix }}\" data-csrf=\"{{ csrf }}\" data-n=\"{{ n }}\">Board</p>{% endblock %}");
        $this->write('app/Folio/Stats/assets/stats.css', '.probe{color:red}');
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => ['stats' => new \stdClass()]]));
    }

    /** @return array{string, list<string>} the probe element and the layout's stylesheet links */
    private function parts(string $html): array
    {
        preg_match('#<p class="probe"[^>]*>#', $html, $probe);
        preg_match_all('#<link rel="stylesheet" href="/folio/_assets/x/stats/[^"]+">#', $html, $links);

        return [$probe[0] ?? '', $links[0]];
    }

    #[RunInSeparateProcess]
    public function test_an_override_returning_a_screen_view_renders_like_a_screen(): void
    {
        $this->extension();
        $this->write('app/Folio/Stats/Screens/Board.php', <<<'PHP'
            <?php
            namespace App\Folio\Stats\Screens;
            #[\Preflow\Folio\Extension\Attributes\FolioScreen('board', 'Board', permission: 'stats.view', assets: ['stats.css'])]
            final class Board
            {
                #[\Preflow\Routing\Attributes\Get('/')]
                public function index(): \Preflow\Folio\Extension\Screen\ScreenView
                {
                    return new \Preflow\Folio\Extension\Screen\ScreenView('@stats/board.twig', ['n' => 7]);
                }
            }
            PHP);
        $this->write('app/Folio/Overrides/Content/Index.php', <<<'PHP'
            <?php
            namespace App\Folio\Overrides\Content;
            final class Index implements \Preflow\Folio\Override\OverridableAction
            {
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface|\Preflow\Folio\Extension\Screen\ScreenView
                {
                    return new \Preflow\Folio\Extension\Screen\ScreenView('@stats/board.twig', ['n' => 7], ['@stats/stats.css']);
                }
            }
            PHP);

        $app = Application::create($this->dir);
        $app->boot();
        $f = new Psr17Factory();
        $this->login($app, $f);

        $screen = $app->handle($f->createServerRequest('GET', '/folio/board'));
        $override = $app->handle($f->createServerRequest('GET', '/folio'));

        $this->assertSame(200, $screen->getStatusCode());
        $this->assertSame(200, $override->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $override->getHeaderLine('Content-Type'));
        $screenParts = $this->parts((string) $screen->getBody());
        $overrideParts = $this->parts((string) $override->getBody());
        $this->assertStringContainsString('data-prefix="/folio"', $overrideParts[0]);
        $this->assertMatchesRegularExpression('/data-csrf="[^"]+"/', $overrideParts[0]);
        $this->assertCount(1, $overrideParts[1]);
        $this->assertSame($screenParts[1], $overrideParts[1]);
        $this->assertSame(
            preg_replace('/data-csrf="[^"]*"/', '', $screenParts[0]),
            preg_replace('/data-csrf="[^"]*"/', '', $overrideParts[0]),
        );
        $this->assertStringContainsString('data-folio-prefix="/folio"', (string) $override->getBody(), 'inside the admin layout');
    }

    #[RunInSeparateProcess]
    public function test_an_override_returning_a_response_passes_through_unchanged(): void
    {
        $this->write('app/Folio/Overrides/Content/Index.php', <<<'PHP'
            <?php
            namespace App\Folio\Overrides\Content;
            final class Index implements \Preflow\Folio\Override\OverridableAction
            {
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface|\Preflow\Folio\Extension\Screen\ScreenView
                {
                    return new \Nyholm\Psr7\Response(200, ['X-Probe' => '1'], 'PLAIN');
                }
            }
            PHP);

        $app = Application::create($this->dir);
        $app->boot();
        $f = new Psr17Factory();
        $this->login($app, $f);

        $res = $app->handle($f->createServerRequest('GET', '/folio'));
        $this->assertSame('PLAIN', (string) $res->getBody());
        $this->assertSame('1', $res->getHeaderLine('X-Probe'));
    }

    #[RunInSeparateProcess]
    public function test_a_bare_stylesheet_from_an_override_throws_naming_the_override(): void
    {
        $this->extension();
        $this->write('app/Folio/Overrides/Content/Index.php', <<<'PHP'
            <?php
            namespace App\Folio\Overrides\Content;
            final class Index implements \Preflow\Folio\Override\OverridableAction
            {
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface|\Preflow\Folio\Extension\Screen\ScreenView
                {
                    return new \Preflow\Folio\Extension\Screen\ScreenView('@stats/board.twig', ['n' => 1], ['stats.css']);
                }
            }
            PHP);

        $app = Application::create($this->dir);
        $app->boot();
        $f = new Psr17Factory();
        $this->login($app, $f);

        $res = $app->handle($f->createServerRequest('GET', '/folio'));
        $this->assertSame(500, $res->getStatusCode());
        $this->assertStringNotContainsString('class="probe"', (string) $res->getBody());
    }
}
