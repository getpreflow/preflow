<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Preview\PreviewToken;
use Preflow\Folio\Tests\Fixtures\Preview\PreviewProbe;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Publishing\LiveContent;
use Preflow\Rbac\RoleStore;
use Preflow\Testing\ChecksFrameBalance;
use Psr\Http\Message\ResponseInterface;

/**
 * View any address as a role (0.83.0): the address preview (GET /folio/preview/address/page?path=&as=)
 * and its screen (GET /folio/preview/address). The site: the post p1 ("Hello", working copy
 * "Draft"/"Entwurf") and an app page /account that is no record: members see the member area,
 * everyone else the sign-in, and the probe component (PreviewProbe: a member-only action).
 *
 * Staff: admin ("*"), lead (site.view_as and site.view_as_address), roles (site.view_as only),
 * addresses (site.view_as_address only), writer (edits posts). Member roles: gold
 * (members.access), pending (nothing).
 */
final class AddressPreviewTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private ?Application $session = null;
    private string $csrf = '';

    protected function setUp(): void
    {
        Contexts::reset();
        $this->scaffoldPublishing();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Preview', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-address-preview-test-key-0123', 'url' => 'https://site.test'];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><head><title>{{ record.title }}</title></head><body><h1>{{ record.title }}</h1></body></html>');
        $this->write('app/pages/account.twig', '<html><head><title>Account</title></head><body>'
            . '{% if can("members.access") %}<p class="area">MEMBER AREA</p>{% else %}<p class="area">SIGN IN</p>{% endif %}'
            . "{{ component('" . addslashes(PreviewProbe::class) . "') }}</body></html>");
        ini_set('error_log', $this->dir . '/php.log');
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, " . var_export(AddressPreviewSiteProvider::class, true) . "];\n");

        $store = $this->app()->container()->get(RoleStore::class);
        $store->install();
        $store->seed([
            'staff' => [
                'admin' => ['*'],
                'lead' => ['post.view', 'post.edit', 'site.view_as', 'site.view_as_address'],
                'roles' => ['post.view', 'post.edit', 'site.view_as'],
                'addresses' => ['post.view', 'post.edit', 'site.view_as_address'],
                'writer' => ['post.view', 'post.edit'],
            ],
            'members' => ['gold' => ['members.access'], 'pending' => []],
        ]);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de']]), null);
        $this->working('post', 'p1', ['title' => ['en' => 'Draft', 'de' => 'Entwurf'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de']]);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $users = [];
        foreach (['admin', 'lead', 'roles', 'addresses', 'writer'] as $role) {
            $users[] = ['email' => $role . '@site.test', 'password_hash' => $hash, 'roles' => [$role]];
        }
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => $users]],
            'session' => ['cookie' => 'folio_address_preview', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_an_app_route_without_a_record_previews_as_a_member_role(): void
    {
        $this->signIn('lead@site.test');

        $gold = $this->send('GET', '/folio/preview/address/page?path=' . rawurlencode('/account') . '&as=member:gold');
        $visitor = (string) $this->send('GET', '/folio/preview/address/page?path=' . rawurlencode('/account'))->getBody();

        $body = (string) $gold->getBody();
        $this->assertSame(200, $gold->getStatusCode(), $body);
        $this->assertStringContainsString('<p class="area">MEMBER AREA</p>', $body, 'the role\'s view of the app page');
        $this->assertStringContainsString('<p class="area">SIGN IN</p>', $visitor, 'a visitor\'s view');
        $this->assertStringContainsString('<head><base href="/account"><title>', $body, 'relative addresses resolve against the page');
        $this->assertStringContainsString('data-folio-preview-report', $body, 'the preview bar');
        $this->assertSame('private, no-store', $gold->getHeaderLine('Cache-Control'));
        $this->assertSame('noindex', $gold->getHeaderLine('X-Robots-Tag'));

        // A record's address works too, in the language its prefix names, with the working copy.
        $this->assertStringContainsString('<h1>Entwurf</h1>', (string) $this->send('GET', '/folio/preview/address/page?path=' . rawurlencode('/de/hallo'))->getBody());
        // Normalised: without the leading slash (the field shows it), dot segments, doubled slashes inside.
        foreach (['account', '/x/../account', '/./account', '/account/', '/a//../account', '/x/%2e%2e/account', '/%61ccount'] as $typed) {
            $this->assertStringContainsString('MEMBER AREA', (string) $this->send('GET', '/folio/preview/address/page?path=' . rawurlencode($typed) . '&as=member:gold')->getBody(), $typed);
        }
        $this->assertSame(0, Contexts::depth());
    }

    #[RunInSeparateProcess]
    public function test_addresses_off_the_site_are_refused(): void
    {
        $this->signIn('admin@site.test');

        foreach (['https://elsewhere.test/account', 'https://site.test/account', '//elsewhere.test/account', '///elsewhere.test', '/\\elsewhere.test', '\\\\elsewhere.test',
            '\\/elsewhere.test', 'javascript:alert(1)', 'mailto:someone@site.test', 'data:text/html,x', ' //elsewhere.test', "/acc\nount", '/account?x=1', '/account#top',
            '/folio/post', '/folio', '/--component/x', '', '/../..',
            // Encoded, the same: an encoded admin segment, dot segment, backslash or control character.
            '/%66olio/post', '/de/%66olio', '/%2e%2e', '/%5Celsewhere.test', '/acc%0Aount'] as $bad) {
            $answer = $this->send('GET', '/folio/preview/address/page?path=' . rawurlencode($bad));
            $this->assertSame(400, $answer->getStatusCode(), var_export($bad, true));
            $this->assertStringContainsString('Not an address of this site', (string) $answer->getBody(), var_export($bad, true));
            $this->assertStringNotContainsString('MEMBER AREA', (string) $answer->getBody());
        }
        $this->assertSame(400, $this->send('GET', '/folio/preview/address/page?path[]=/account')->getStatusCode(), 'one value, never a list');

        // The screen says so next to the field and shows no frame.
        $screen = (string) $this->send('GET', '/folio/preview/address?path=' . rawurlencode('//elsewhere.test/account'))->getBody();
        $this->assertStringContainsString('Not an address of this site', $screen);
        $this->assertStringNotContainsString('folio-page-frame', $screen);
    }

    #[RunInSeparateProcess]
    public function test_the_address_preview_needs_both_permissions(): void
    {
        $this->signIn('roles@site.test');
        $this->assertSame(403, $this->send('GET', '/folio/preview/address/page?path=%2Faccount&as=member:gold')->getStatusCode(), 'site.view_as alone is the record preview only');
        $this->assertSame(403, $this->send('GET', '/folio/preview/address/page?path=%2Faccount')->getStatusCode(), 'also as a visitor');
        $this->assertSame(403, $this->send('GET', '/folio/preview/address')->getStatusCode(), 'and the screen');
        $this->assertSame(200, $this->send('GET', '/folio/preview/page/post/p1?as=member:gold')->getStatusCode(), 'the record preview stays');
        $form = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();
        $this->assertStringNotContainsString('/folio/preview/address', $form, 'no way to the screen');

        $this->assertStringNotContainsString('href="/folio/preview/address"', $form, 'no Settings → View as');

        $this->signIn('addresses@site.test');
        $this->assertSame(403, $this->send('GET', '/folio/preview/address/page?path=%2Faccount&as=member:gold')->getStatusCode(), 'site.view_as_address without site.view_as');
        $this->assertSame(403, $this->send('GET', '/folio/preview/address')->getStatusCode());
        // What it cannot open it is not offered: no Settings → View as (also not in the ⌘K screens), no eye icon.
        $form = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();
        $this->assertStringNotContainsString('/folio/preview/address', $form, 'neither the sidebar item nor the editor\'s link');

        $this->signIn('writer@site.test');
        $this->assertSame(403, $this->send('GET', '/folio/preview/address/page?path=%2Faccount')->getStatusCode());

        $this->signIn('lead@site.test');
        $this->assertSame(403, $this->send('GET', '/folio/preview/address/page?path=%2Faccount&as=member:nobody')->getStatusCode(), 'only a role the editor may view as');
        $this->assertSame(403, $this->send('GET', '/folio/preview/address/page?path=%2Faccount&as[]=member:gold')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_follow_up_requests_keep_the_role_and_are_checked_again(): void
    {
        $this->signIn('lead@site.test');
        $gold = (string) $this->send('GET', '/folio/preview/address/page?path=%2Faccount&as=member:gold')->getBody();
        $pending = (string) $this->send('GET', '/folio/preview/address/page?path=%2Faccount&as=member:pending')->getBody();
        $this->assertStringContainsString(PreviewToken::PARAM . '=', $gold, 'the page\'s follow-ups carry a token');
        $members = $this->url($gold, 'members', 'hx-post');
        $visitorLazy = $this->url((string) $this->send('GET', '/folio/preview/address/page?path=%2Faccount')->getBody(), 'lazy', 'hx-get');
        $this->assertSame(200, $this->send('GET', $visitorLazy, ['HX-Request' => 'true'])->getStatusCode(), 'a visitor view\'s lazy part');

        $answer = $this->send('POST', $members, ['HX-Request' => 'true']);
        $this->assertSame(200, $answer->getStatusCode(), (string) $answer->getBody());
        $this->assertStringContainsString('welcome, member', (string) $answer->getBody(), 'the action runs as the gold member');
        $this->assertSame(403, $this->send('POST', $this->url($pending, 'members', 'hx-post'), ['HX-Request' => 'true'])->getStatusCode(), 'a pending member may not');

        // Taking site.view_as_address away ends the address preview at its next request.
        $this->session->container()->get(RoleStore::class)->put('staff', 'lead', null, [], ['site.view_as_address']);
        $this->assertSame(403, $this->send('POST', $members, ['HX-Request' => 'true'])->getStatusCode(), 'the token requires site.view_as_address too');
        $this->assertSame(403, $this->send('GET', $visitorLazy, ['HX-Request' => 'true'])->getStatusCode(), 'also a visitor view\'s');

        // So does taking site.view_as away, also from a visitor's view.
        $this->session->container()->get(RoleStore::class)->put('staff', 'lead', null, ['site.view_as_address'], []);
        $visitorLazy = $this->url((string) $this->send('GET', '/folio/preview/address/page?path=%2Faccount')->getBody(), 'lazy', 'hx-get');
        $this->session->container()->get(RoleStore::class)->put('staff', 'lead', null, [], ['site.view_as']);
        $this->assertSame(403, $this->send('GET', $visitorLazy, ['HX-Request' => 'true'])->getStatusCode(), 'site.view_as taken away ends a visitor view too');
    }

    #[RunInSeparateProcess]
    public function test_the_screen_frames_the_address_and_the_editor_links_to_it(): void
    {
        $this->signIn('lead@site.test');

        $screen = $this->send('GET', '/folio/preview/address?path=' . rawurlencode('account') . '&as=member:gold');
        $body = (string) $screen->getBody();
        $this->assertSame(200, $screen->getStatusCode(), $body);
        $this->assertStringContainsString('name="path" value="/account"', $body, 'the address, normalised');
        $this->assertStringContainsString('<option value="member:gold" selected>', $body, 'the chosen role');
        $this->assertStringContainsString('src="/folio/preview/address/page?path=%2Faccount&amp;as=member%3Agold"', $body);
        $this->assertStringContainsString('class="folio-preview-frame folio-page-frame', $body);
        // Without an address: the field, no frame.
        $this->assertStringNotContainsString('folio-page-frame', (string) $this->send('GET', '/folio/preview/address')->getBody());

        // The record's editor opens it at the record's address; the sidebar lists it.
        $form = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();
        $this->assertStringContainsString('href="/folio/preview/address?path=%2Fhello"', $form);
        $this->assertStringContainsString('href="/folio/preview/address"', $form, 'Settings → View as');

        $this->assertSame(['label' => 'View any address as a role', 'group' => 'Site'], $this->session->container()->get(PermissionRegistry::class)->all()['site.view_as_address'] ?? null, 'Folio declares it, beside site.view_as');
    }

    /** The URL of $attribute on $html's element of class $class. */
    private function url(string $html, string $class, string $attribute): string
    {
        $this->assertSame(1, preg_match('#class="' . $class . '"[^>]*' . $attribute . '="([^"]+)"#', $html, $m), "{$class} {$attribute} in: {$html}");

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    private function signIn(string $email): void
    {
        $f = new Psr17Factory();
        if ($this->session !== null) {
            $this->session->handle($f->createServerRequest('POST', '/folio/logout')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody(['_csrf_token' => $this->csrf]));
        }
        $this->session ??= $this->app();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->csrf = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->csrf, 'email' => $email, 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, string> $headers */
    private function send(string $method, string $uri, array $headers = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri)->withHeader('Sec-Fetch-Site', 'same-origin');
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->session->handle($request);
    }
}

/** Site code of the test app: the member permission, and what the probe component reads. */
final class AddressPreviewSiteProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->get(PermissionRegistry::class)->declare('members.access', 'Member area', 'Members');
    }

    public function boot(Container $container): void
    {
        PreviewProbe::$title = static fn (): string => (string) ($container->get(LiveContent::class)->find('post', 'p1')?->localized(Contexts::current()->locale)->get('title') ?? '');
        PreviewProbe::$member = static fn (): bool => $container->get(Access::class)->can('members.access');
        PreviewProbe::$write = null;
    }
}
