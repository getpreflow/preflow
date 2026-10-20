<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * Folio with auth and preflow/roles: an editor (view, edit, assets.view,
 * dashboard on posts, no publish/create/delete) and a viewer (post.view only).
 */
final class FolioPermissionsTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;

    private string $token = '';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $users = [];
        foreach (['admin', 'editor', 'viewer'] as $role) {
            $users[] = ['email' => "{$role}@site.test", 'password_hash' => $hash, 'roles' => [$role]];
        }
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => $users]],
            'session' => ['cookie' => 'folio_perm_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            'editor' => ['folio.dashboard', 'post.view', 'post.edit', 'assets.view'],
            'viewer' => ['post.view'],
        ]], true) . ";\n");
        $this->withRoles(true);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** One app per test: the login session lives in-process and can't be restarted. */
    private function app(): Application
    {
        if ($this->session === null) {
            $this->session = Application::create($this->dir);
            $this->session->boot();
        }

        return $this->session;
    }

    private function withRoles(bool $on): void
    {
        $this->session = null;
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class"
            . ($on ? ', \\Preflow\\Roles\\RolesServiceProvider::class' : '') . "];\n");
    }

    /** Log in once; later requests reuse the app (the session lives in-process). */
    private function as(string $role): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Draft'], 'slug' => ['en' => 'hello']]);
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body = [], array $headers = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        if ($method === 'POST') {
            $req = $req->withParsedBody($body + ['_csrf_token' => $this->token]);
        }

        return $this->session->handle($req);
    }

    #[RunInSeparateProcess]
    public function test_editor_cannot_publish_through_update(): void
    {
        $this->as('editor');
        $res = $this->send('POST', '/folio/post/p1', ['title' => 'Edited', 'slug' => 'hello', '_locale' => 'en', '_action' => 'publish']);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertNull($this->publisher()->live('post', 'p1'));
        $this->assertSame(404, $this->get('/hello')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_editor_cannot_schedule_a_publish(): void
    {
        $this->as('editor');
        $res = $this->send('POST', '/folio/post/p1', ['title' => 'Edited', 'slug' => 'hello', '_locale' => 'en', '_publish_at' => '2099-01-01T10:00']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('', (string) ($this->stored('post', 'p1')->get('_publish_at') ?? ''));
        $this->assertSame(['en' => 'Edited'], $this->stored('post', 'p1')->get('title'));
    }

    #[RunInSeparateProcess]
    public function test_editor_saves_drafts(): void
    {
        $this->as('editor');
        $this->assertSame(302, $this->send('POST', '/folio/post/p1', ['title' => 'Edited', 'slug' => 'hello', '_locale' => 'en'])->getStatusCode());
        $this->assertSame(200, $this->send('POST', '/folio/post/p1/autosave', ['title' => 'Auto', 'slug' => 'hello', '_locale' => 'en'])->getStatusCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function writeRoutes(): iterable
    {
        foreach ([
            ['GET', '/folio/post/new'],
            ['POST', '/folio/post'],
            ['POST', '/folio/post/p1'],
            ['POST', '/folio/post/p1/delete'],
            ['POST', '/folio/post/p1/move'],
            ['POST', '/folio/post/p1/autosave'],
            ['POST', '/folio/post/p1/discard'],
            ['POST', '/folio/post/p1/unpublish'],
            ['POST', '/folio/post/p1/share'],
            ['POST', '/folio/post/p1/share/revoke'],
            ['POST', '/folio/post/p1/revisions/r1/restore'],
            ['POST', '/folio/post/p1/preview'],
            ['POST', '/folio/post/preview'],
            ['GET', '/folio/_blocks/hero/new'],
            ['POST', '/folio/_blocks/duplicate'],
            ['GET', '/folio/assets'],
            ['POST', '/folio/assets'],
            ['GET', '/folio/assets/a1'],
            ['POST', '/folio/assets/a1'],
            ['POST', '/folio/assets/a1/delete'],
            ['GET', '/folio/site'],
        ] as [$method, $uri]) {
            yield "{$method} {$uri}" => [$method, $uri];
        }
    }

    #[RunInSeparateProcess]
    #[DataProvider('writeRoutes')]
    public function test_viewer_gets_403_on_every_write_route(string $method, string $uri): void
    {
        $this->as('viewer');
        $this->assertSame(403, $this->send($method, $uri, ['title' => 'X', 'slug' => 'x', '_locale' => 'en', 'direction' => 'up'])->getStatusCode());
        $this->assertSame(['en' => 'Draft'], $this->stored('post', 'p1')->get('title'));
    }

    #[RunInSeparateProcess]
    public function test_viewer_reads_posts(): void
    {
        $this->as('viewer');
        $this->assertSame(200, $this->send('GET', '/folio/post')->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/folio/post/p1/edit')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_forbidden_page_is_rendered_in_the_admin(): void
    {
        $this->as('viewer');
        $body = (string) $this->send('GET', '/folio/post/new')->getBody();
        $this->assertStringContainsString('class="folio-shell"', $body);
        $this->assertStringContainsString('have access to this', $body);
    }

    #[RunInSeparateProcess]
    public function test_dashboard_without_permission_goes_to_the_first_allowed_type(): void
    {
        $this->as('viewer');
        $res = $this->send('GET', '/folio');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/post', $res->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_unknown_type_is_still_404(): void
    {
        $this->as('editor');
        $this->assertSame(404, $this->send('GET', '/folio/nope')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_sidebar_and_buttons_follow_permissions_for_an_editor(): void
    {
        $this->as('editor');
        $list = (string) $this->send('GET', '/folio/post')->getBody();
        $this->assertStringContainsString('href="/folio/post/p1/edit"', $list);
        $this->assertStringNotContainsString('/folio/post/p1/delete', $list);
        $this->assertStringNotContainsString('href="/folio/post/new"', $list);
        $this->assertStringNotContainsString('href="/folio/site"', $list);   // no site.view
        $this->assertStringContainsString('href="/folio/assets"', $list);

        $form = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();
        $this->assertStringContainsString('Save draft', $form);
        $this->assertStringNotContainsString('value="publish"', $form);
        $this->assertStringNotContainsString('name="_publish_at"', $form);
        $this->assertStringNotContainsString('<fieldset class="folio-fields" disabled', $form);
    }

    #[RunInSeparateProcess]
    public function test_sidebar_and_buttons_follow_permissions_for_a_viewer(): void
    {
        $this->as('viewer');
        $list = (string) $this->send('GET', '/folio/post')->getBody();
        $this->assertStringNotContainsString('href="/folio/assets"', $list);
        $this->assertStringNotContainsString('/move"', $list);
        $this->assertStringNotContainsString('>Edit</a>', $list);

        $form = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();
        $this->assertStringContainsString('<fieldset class="folio-fields" disabled', $form);
        $this->assertStringNotContainsString('Save draft', $form);
        $this->assertStringNotContainsString('/autosave', $form);
        $this->assertStringNotContainsString('/discard', $form);
    }

    #[RunInSeparateProcess]
    public function test_block_editor_requires_some_edit_permission(): void
    {
        $this->as('editor');
        $this->assertNotSame(403, $this->send('GET', '/folio/_blocks/hero/new?name=sections')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_htmx_403_carries_a_message(): void
    {
        $this->as('viewer');
        $res = $this->send('POST', '/folio/post/p1/autosave', ['title' => 'X'], ['HX-Request' => 'true']);

        $this->assertSame(403, $res->getStatusCode());
        $trigger = json_decode($res->getHeaderLine('HX-Trigger'), true);
        $this->assertIsString($trigger['folio:error']['message'] ?? null);
    }

    #[RunInSeparateProcess]
    public function test_admin_role_may_do_everything(): void
    {
        $this->as('admin');
        $res = $this->send('POST', '/folio/post/p1', ['title' => 'Live', 'slug' => 'hello', '_locale' => 'en', '_action' => 'publish']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(200, $this->get('/hello')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_without_roles_package_everything_is_allowed(): void
    {
        $this->withRoles(false);
        $this->as('viewer'); // a role nobody defines: without preflow/roles, staff may do everything
        $res = $this->send('POST', '/folio/post/p1', ['title' => 'Live', 'slug' => 'hello', '_locale' => 'en', '_action' => 'publish']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode());
        $this->assertStringContainsString('value="publish"', (string) $this->send('GET', '/folio/post/p1/edit')->getBody());
    }
}
