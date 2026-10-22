<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\UndeclaredPermission;
use Preflow\Core\Application;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Folio\Publishing\PreviewLinks;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * A "resource" type gated by frontend.permission "members.access" (declared by a
 * "members" extension) with a locale-mapped login, a "vault" type gated without
 * a login, staff (admin: "*") and members (member: members.access) on two guards.
 */
final class FrontendGateTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;

    private string $token = '';

    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => [
                'session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'staff', 'population' => 'staff'],
                'members' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'members', 'population' => 'members'],
            ],
            'providers' => [
                'staff' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ]],
                'members' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => 'member@site.test', 'password_hash' => $hash, 'roles' => ['member']],
                    ['email' => 'guest@site.test', 'password_hash' => $hash, 'roles' => []],
                ]],
            ],
            'session' => ['cookie' => 'folio_gate_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export([
            'staff' => ['admin' => ['*']],
            'members' => ['member' => ['members.access']],
        ], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");
        $this->write('app/Folio/Members/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Members;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'members', label: 'Members')]
            #[\Preflow\Folio\Extension\Attributes\DeclaresPermission('members.access', 'Members area')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');
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

        $fields = [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'translatable' => true],
        ];
        $this->writeModel('resource', ['label' => 'Resources', 'fields' => $fields, 'frontend' => [
            'route' => '/tools/r/{slug}',
            'permission' => 'members.access',
            'login' => ['en' => '/tools/login', 'de' => '/de/tools/login'],
        ]]);
        $this->writeModel('vault', ['label' => 'Vault', 'fields' => $fields, 'frontend' => [
            'route' => '/vault/{slug}',
            'permission' => 'members.access',
        ]]);
        $this->write('resources/folio/frontend/resource.twig', '<h1>{{ record.title }}</h1>');
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    /** One app per test: the session lives in-process. */
    private function app(): Application
    {
        if ($this->session === null) {
            $this->session = Application::create($this->dir);
            $this->session->boot();
        }

        return $this->session;
    }

    /** Signs in through the session key a guard reads (members have no login route here). */
    private function signIn(string $key, string $email): void
    {
        $this->app()->container()->get(SessionInterface::class)->set($key, $email);
    }

    private function loginStaff(): void
    {
        $f = new Psr17Factory();
        $page = (string) $this->app()->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->app()->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    private function published(string $type, string $id, string $title, string $slug, string $slugDe = ''): void
    {
        $data = ['title' => ['en' => $title, 'de' => $title], 'slug' => ['en' => $slug] + ($slugDe !== '' ? ['de' => $slugDe] : []), '_locales' => $slugDe !== '' ? ['en', 'de'] : ['en']];
        $this->publisher()->publish($this->working($type, $id, $data), null);
    }

    private function fetch(string $uri): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->app()->handle($request->withQueryParams($query));
    }

    /** A share-preview token for a record's working copy, as PublishingController::share makes it. */
    private function shareToken(string $type, string $id): string
    {
        $record = $this->stored($type, $id);
        $record->set('_share_token', bin2hex(random_bytes(8)));
        $this->dm()->saveType($record, false);

        return $this->app()->container()->get(PreviewLinks::class)->create($type, $id, (string) $record->get('_share_token'));
    }

    #[RunInSeparateProcess]
    public function test_gated_type_redirects_to_login_with_next(): void
    {
        $this->published('resource', 'r1', 'Members guide', 'guide', 'anleitung');

        $res = $this->fetch('/tools/r/guide');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/tools/login?next=%2Ftools%2Fr%2Fguide', $res->getHeaderLine('Location'));
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringNotContainsString('Members guide', (string) $res->getBody());

        $de = $this->fetch('/de/tools/r/anleitung');
        $this->assertSame(302, $de->getStatusCode());
        $this->assertSame('/de/tools/login?next=%2Fde%2Ftools%2Fr%2Fanleitung', $de->getHeaderLine('Location'));

        // A member without the permission is sent to the login too.
        $this->signIn('_auth_user_id.members', 'guest@site.test');
        $this->assertSame(302, $this->fetch('/tools/r/guide')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_gated_type_404_without_login_config(): void
    {
        $this->published('vault', 'v1', 'Vault page', 'secret');

        $res = $this->fetch('/vault/secret');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringNotContainsString('Vault page', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_member_with_permission_sees_page_no_store(): void
    {
        $this->published('resource', 'r1', 'Members guide', 'guide');
        $this->signIn('_auth_user_id.members', 'member@site.test');

        $res = $this->fetch('/tools/r/guide');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Members guide', (string) $res->getBody());
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));

        $this->assertSame(404, $this->fetch('/tools/r/missing')->getStatusCode()); // allowed, unknown slug: plain 404
    }

    #[RunInSeparateProcess]
    public function test_staff_with_star_sees_page(): void
    {
        $this->published('resource', 'r1', 'Members guide', 'guide');
        $this->loginStaff();

        $res = $this->fetch('/tools/r/guide');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Members guide', (string) $res->getBody());
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));

        // Folio's live preview renders gated types too (admin route, not the frontend gate).
        $preview = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/resource/r1/preview')->withParsedBody([
            '_csrf_token' => $this->token, 'title' => 'Draft guide', 'slug' => 'guide', '_locale' => 'en',
        ]));
        $this->assertSame(200, $preview->getStatusCode());
        $this->assertStringContainsString('Draft guide', (string) $preview->getBody());
    }

    #[RunInSeparateProcess]
    public function test_share_preview_still_needs_valid_token(): void
    {
        $this->working('resource', 'r2', ['title' => ['en' => 'Unreleased guide'], 'slug' => ['en' => 'draft']]);
        $token = $this->shareToken('resource', 'r2');
        $tampered = substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A');

        // Logged out, a valid link does not open the gate.
        $out = $this->fetch('/tools/r/draft?folio_preview=' . $token);
        $this->assertSame(302, $out->getStatusCode());
        $this->assertSame('/tools/login?next=%2Ftools%2Fr%2Fdraft', $out->getHeaderLine('Location'));
        $this->assertStringNotContainsString('Unreleased guide', (string) $out->getBody());

        // Staff pass the gate, but an unpublished record still needs a valid token.
        $this->loginStaff();
        $this->assertSame(404, $this->fetch('/tools/r/draft?folio_preview=' . $tampered)->getStatusCode());
        $this->assertSame(404, $this->fetch('/tools/r/draft')->getStatusCode());
        $ok = $this->fetch('/tools/r/draft?folio_preview=' . $token);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('Unreleased guide', (string) $ok->getBody());
        $this->assertSame('private, no-store', $ok->getHeaderLine('Cache-Control'));
    }

    #[RunInSeparateProcess]
    public function test_gated_type_refused_without_auth_config(): void
    {
        unlink($this->dir . '/config/auth.php');
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $this->published('resource', 'r1', 'Members guide', 'guide');
        $this->published('vault', 'v1', 'Vault page', 'secret');

        $res = $this->fetch('/tools/r/guide');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/tools/login?next=%2Ftools%2Fr%2Fguide', $res->getHeaderLine('Location'));
        $this->assertSame(404, $this->fetch('/vault/secret')->getStatusCode());
    }

    /** A public post listing and finding resources. */
    private function listingPost(): void
    {
        $this->write('resources/folio/frontend/post.twig', '<ul>{% for r in folio_list("resource") %}<li>{{ r.title }}</li>{% endfor %}</ul>'
            . '<p class="found">{{ folio_find("resource", "r1").title|default("none") }}</p>');
        $this->published('post', 'p1', 'Public post', 'hello');
        $this->published('resource', 'r1', 'Members guide', 'guide');
    }

    #[RunInSeparateProcess]
    public function test_folio_list_hides_gated_type_from_visitors(): void
    {
        $this->listingPost();

        $body = (string) $this->fetch('/hello')->getBody();
        $this->assertStringNotContainsString('Members guide', $body);
        $this->assertStringContainsString('<p class="found">none</p>', $body);

        $this->signIn('_auth_user_id.members', 'guest@site.test'); // signed in, without the permission
        $this->assertStringNotContainsString('Members guide', (string) $this->fetch('/hello')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_folio_list_shows_gated_type_to_member(): void
    {
        $this->listingPost();
        $this->signIn('_auth_user_id.members', 'member@site.test');

        $body = (string) $this->fetch('/hello')->getBody();
        $this->assertStringContainsString('<li>Members guide</li>', $body);
        $this->assertStringContainsString('<p class="found">Members guide</p>', $body);
    }

    #[RunInSeparateProcess]
    public function test_ungated_types_unchanged(): void
    {
        $this->published('post', 'p1', 'Public post', 'hello');

        $res = $this->fetch('/hello');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Public post', (string) $res->getBody());
        $this->assertSame('', $res->getHeaderLine('Cache-Control'));
    }

    #[RunInSeparateProcess]
    public function test_undeclared_permission_fails_loudly_in_debug(): void
    {
        $this->writeModel('vault', ['label' => 'Vault', 'fields' => ['slug' => ['type' => 'string']], 'frontend' => [
            'route' => '/vault/{slug}',
            'permission' => 'members.typo',
        ]]);
        $this->publisher()->publish($this->working('vault', 'v1', ['slug' => 'secret']), null);

        $this->expectException(UndeclaredPermission::class);
        $this->app()->container()->get(\Preflow\Folio\Http\FrontendController::class)
            ->show((new Psr17Factory())->createServerRequest('GET', '/vault/secret')->withAttribute('path', 'vault/secret'));
    }
}
