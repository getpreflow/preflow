<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * A full app with auth and preflow/roles and extensions (written by the test) whose type
 * customizations put HTML above the type's list (#[ListNotice]) and above the dashboard's
 * "Needs you" and content (#[DashboardNotice]).
 */
final class NoticeHooksTest extends TestCase
{
    use PublishingScaffold;

    private \Closure $autoload;
    private ?Application $session = null;
    /** @var array<string, array{disabled_parts: list<string>}> */
    private array $enabled = [];

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('contact', ['label' => 'Contacts', 'fields' => ['name' => ['type' => 'string']]]);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
            ]]],
            'session' => ['cookie' => 'folio_notice_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            'editor' => ['folio.dashboard', 'note.view'], // no contact.view
        ]], true) . ";\n");
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
    public function test_notices_render_on_the_types_list_and_the_dashboard(): void
    {
        $this->customize('crm', 'ContactAdmin', 'contact', list: 'return \'<p class="crm-list">Two contacts wait</p>\';', dashboard: 'return \'<p class="crm-dash">Contacts wait</p>\';');
        $this->customize('zed', 'ContactMore', 'contact', list: 'return \'<p class="zed-list">More</p>\';', dashboard: 'return null;');
        $this->as('admin');

        $list = $this->send('GET', '/folio/contact');
        $this->assertSame(200, $list->getStatusCode());
        $body = (string) $list->getBody();
        // Printed as written (raw), both customizations, in extension name order, above the list.
        $this->assertStringContainsString('<p class="crm-list">Two contacts wait</p>', $body);
        $this->assertStringContainsString('<p class="zed-list">More</p>', $body);
        $this->assertLessThan(strpos($body, 'zed-list'), strpos($body, 'crm-list'));
        $this->assertLessThan(strpos($body, 'data-folio-list-results'), strpos($body, 'zed-list'));
        $this->assertStringNotContainsString('crm-dash', $body);

        $dashboard = (string) $this->send('GET', '/folio')->getBody();
        $this->assertStringContainsString('<p class="crm-dash">Contacts wait</p>', $dashboard);
        // Above "Needs you" and the content (0.76; before, above the cards).
        $this->assertLessThan(strpos($dashboard, 'folio-dash-content'), strpos($dashboard, 'crm-dash'));
        $this->assertLessThan(strpos($dashboard, 'crm-dash'), strpos($dashboard, 'folio-dash-band'), 'below the ink band');
        $this->assertStringNotContainsString('crm-list', $dashboard);

        // Another type's list has none.
        $this->assertStringNotContainsString('crm-list', (string) $this->send('GET', '/folio/note')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_list_notice_is_not_part_of_the_lists_partial_refresh(): void
    {
        $this->customize('crm', 'ContactAdmin', 'contact', list: 'return \'<p class="crm-list">x</p>\';');
        $this->as('admin');

        $partial = $this->send('GET', '/folio/contact?_partial=1');

        $this->assertSame(200, $partial->getStatusCode());
        $this->assertStringNotContainsString('crm-list', (string) $partial->getBody());
    }

    #[RunInSeparateProcess]
    public function test_throwing_or_empty_notices_render_nothing_and_keep_the_others(): void
    {
        $this->customize('crm', 'ContactAdmin', 'contact', list: 'throw new \\RuntimeException("db down");', dashboard: 'throw new \\RuntimeException("db down");');
        $this->customize('nb', 'NoteAdmin', 'note', list: 'return "";', dashboard: 'return \'<p class="note-dash">ok</p>\';');
        $this->as('admin');

        $list = $this->send('GET', '/folio/contact');
        $this->assertSame(200, $list->getStatusCode());
        $this->assertStringNotContainsString('folio-page-notices', (string) $list->getBody());
        $this->assertStringNotContainsString('folio-page-notices', (string) $this->send('GET', '/folio/note')->getBody());

        $dashboard = $this->send('GET', '/folio');
        $this->assertSame(200, $dashboard->getStatusCode());
        $this->assertStringContainsString('<p class="note-dash">ok</p>', (string) $dashboard->getBody());
    }

    #[RunInSeparateProcess]
    public function test_dashboard_notice_only_for_types_the_user_can_view(): void
    {
        $marker = $this->dir . '/notice-called';
        $this->customize('crm', 'ContactAdmin', 'contact', dashboard: 'file_put_contents(' . var_export($marker, true) . ', "x"); return \'<p class="crm-dash">x</p>\';');
        $this->customize('nb', 'NoteAdmin', 'note', dashboard: 'return \'<p class="note-dash">ok</p>\';');
        $this->as('editor');

        $dashboard = (string) $this->send('GET', '/folio')->getBody();

        $this->assertStringNotContainsString('crm-dash', $dashboard);
        $this->assertStringContainsString('<p class="note-dash">ok</p>', $dashboard);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_admin_css_spaces_stacked_notices_and_their_item_list(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../assets/admin.css');
        $this->assertMatchesRegularExpression('/\.folio-page-notices \{[^}]*gap: 10px;/', $css);
        $this->assertStringContainsString('.folio-page-notices > * { margin: 0; }', $css, 'the gap spaces them, not each margin');
        $this->assertMatchesRegularExpression('/\.folio-notice \.folio-notice-list \{ list-style: none;[^}]*padding: 0; \}/', $css);
    }

    #[RunInSeparateProcess]
    public function test_static_notice_method_throws_at_boot(): void
    {
        $this->write('app/Folio/Bad/Extension.php', "<?php\nnamespace App\\Folio\\Bad;\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioExtension(name: 'bad', label: 'bad')]\nfinal class Extension extends \\Preflow\\Folio\\Extension\\Extension {}\n");
        $this->write('app/Folio/Bad/Types/Bad.php', <<<'PHP'
            <?php
            namespace App\Folio\Bad\Types;
            #[\Preflow\Folio\Extension\Attributes\FolioType('contact')]
            final class Bad
            {
                #[\Preflow\Folio\Extension\ListNotice]
                public static function notice(): ?string { return 'x'; }
            }
            PHP);
        $this->enabled['bad'] = ['disabled_parts' => []];
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => $this->enabled]));

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('must be a public, non-static method to carry #[ListNotice]');
        $this->app();
    }

    /** An extension $name customizing $type; $list / $dashboard are the bodies of its notice methods (null: no such method). */
    private function customize(string $name, string $class, string $type, ?string $list = null, ?string $dashboard = null): void
    {
        $ns = 'App\\Folio\\' . ucfirst($name);
        $methods = '';
        if ($list !== null) {
            $methods .= "#[\\Preflow\\Folio\\Extension\\ListNotice]\n    public function listNotice(): ?string { {$list} }\n";
        }
        if ($dashboard !== null) {
            $methods .= "#[\\Preflow\\Folio\\Extension\\DashboardNotice]\n    public function dashboardNotice(): ?string { {$dashboard} }\n";
        }
        $this->write('app/Folio/' . ucfirst($name) . '/Extension.php', "<?php\nnamespace {$ns};\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioExtension(name: '{$name}', label: '{$name}')]\nfinal class Extension extends \\Preflow\\Folio\\Extension\\Extension {}\n");
        $this->write('app/Folio/' . ucfirst($name) . "/Types/{$class}.php", "<?php\nnamespace {$ns}\\Types;\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioType('{$type}')]\nfinal class {$class}\n{\n    {$methods}}\n");
        $this->enabled[$name] = ['disabled_parts' => []];
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => $this->enabled]));
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function as(string $role): void
    {
        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    private function send(string $method, string $uri): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->session->handle($request->withQueryParams($query));
    }
}
