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
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Fixtures\Preview\PreviewProbe;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Publishing\LiveContent;
use Preflow\Rbac\RoleStore;
use Preflow\Testing\ChecksFrameBalance;
use Psr\Http\Message\ResponseInterface;

/**
 * The follow-up requests of Folio's whole-page preview (Task 21): the preview hands its signed
 * token on (component URLs carry `_preview`, <body> tells htmx to send X-Preflow-Preview), so a
 * lazy part of the previewed page shows the draft and a member-only action works under a member
 * role view; nothing else ever carries a token. Plus what the preview explains instead of showing
 * a wrong or empty page.
 *
 * The site: the post p1 published as "Hello", its working copy "Draft"; its page shows the probe
 * component (PreviewProbe: the title as the render reads it, a lazy load, a member-only action, an
 * action that writes a note). Staff: admin ("*"), lead (edits posts, site.view_as), writer (edits
 * posts). Member roles: gold (members.access), pending (nothing).
 */
final class PagePreviewFragmentsTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private ?Application $session = null;
    private string $csrf = '';

    protected function setUp(): void
    {
        Contexts::reset();
        $this->scaffoldPublishing();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Preview', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-page-preview-fragments-key-0123', 'url' => 'https://site.test'];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><head><title>{{ record.title }}</title></head><body><h1>{{ record.title }}</h1>'
            . "{{ component('" . addslashes(PreviewProbe::class) . "') }}<img src=\"logo.png\" alt=\"\"></body></html>");
        ini_set('error_log', $this->dir . '/php.log');
        // Two types without publishing whose addresses overlap: /events/party is both an event and a party.
        foreach (['event' => ['Events', '/events/{slug}'], 'party' => ['Parties', '/{area}/{slug}']] as $key => [$label, $route]) {
            $this->writeModel($key, [
                'label' => $label, 'publishing' => false, 'frontend' => ['route' => $route],
                'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'area' => ['type' => 'string'], 'status' => ['type' => 'string']],
            ]);
            $this->write("resources/folio/frontend/{$key}.twig", '<html><head></head><body><h1>' . strtoupper($key) . ' {{ record.title }}</h1></body></html>');
        }
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, " . var_export(PreviewFragmentsSiteProvider::class, true) . "];\n");

        $store = $this->app()->container()->get(RoleStore::class);
        $store->install();
        $store->seed([
            'staff' => ['admin' => ['*'], 'lead' => ['post.view', 'post.edit', 'post.create', 'site.view_as'], 'writer' => ['post.view', 'post.edit', 'post.create']],
            'members' => ['gold' => ['members.access'], 'pending' => []],
        ]);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], '_locales' => ['en']]), null);
        $this->working('post', 'p1', ['title' => ['en' => 'Draft'], 'slug' => ['en' => 'hello'], '_locales' => ['en']]);
        $this->working('event', 'e1', ['title' => 'Draft party', 'slug' => 'party', 'status' => 'draft']);
        $this->working('party', 'x1', ['title' => 'Other party', 'area' => 'events', 'slug' => 'party', 'status' => 'draft']);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'lead@site.test', 'password_hash' => $hash, 'roles' => ['lead']],
                ['email' => 'writer@site.test', 'password_hash' => $hash, 'roles' => ['writer']],
            ]]],
            'session' => ['cookie' => 'folio_page_preview_fragments', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_tokens_appear_only_in_granted_renders(): void
    {
        $this->signIn('admin@site.test');

        $preview = (string) $this->send('GET', '/folio/preview/page/post/p1')->getBody();
        $token = $this->tokenIn($preview);

        foreach (['lazy' => 'hx-get', 'members' => 'hx-post', 'save' => 'hx-post'] as $class => $attribute) {
            $this->assertStringContainsString(PreviewToken::PARAM . '=' . rawurlencode($token), $this->url($preview, $class, $attribute), "the {$class} URL carries the token");
        }
        $this->assertSame(1, preg_match('#<body hx-headers="([^"]+)">#', $preview, $headers), 'the page tells htmx to send it');
        $this->assertSame([PreviewToken::HEADER => $token], json_decode(html_entity_decode($headers[1], ENT_QUOTES), true));

        // A visitor's page, and the admin's own screens: no token anywhere.
        $visitor = (string) $this->visitor('/hello')->getBody();
        $this->assertStringContainsString('<span class="heading">Hello</span>', $visitor);
        $this->assertStringNotContainsString(PreviewToken::PARAM, $visitor);
        $this->assertStringContainsString('<body><h1>Hello</h1>', $visitor, 'no hx-headers on its body');
        $this->assertStringNotContainsString($token, (string) $this->send('GET', '/folio/post/p1/edit')->getBody());
        // Relative addresses of the previewed page resolve against its own address.
        $this->assertStringContainsString('<head><base href="/hello"><title>', $preview);
    }

    #[RunInSeparateProcess]
    public function test_lazy_fragment_of_a_previewed_page_shows_the_draft(): void
    {
        $this->signIn('admin@site.test');
        $preview = (string) $this->send('GET', '/folio/preview/page/post/p1')->getBody();
        $this->assertStringContainsString('<span class="heading">Draft</span>', $preview, 'the page itself');
        $lazy = $this->url($preview, 'lazy', 'hx-get');

        $fragment = $this->send('GET', $lazy, ['HX-Request' => 'true']);

        $this->assertSame(200, $fragment->getStatusCode(), (string) $fragment->getBody());
        $this->assertStringContainsString('<span class="heading">Draft</span>', (string) $fragment->getBody(), 'the lazy part reads the working copy too');
        $this->assertSame('private, no-store', $fragment->getHeaderLine('Cache-Control'));
        $this->assertSame('noindex', $fragment->getHeaderLine('X-Robots-Tag'));
        $this->assertSame('no-referrer', $fragment->getHeaderLine('Referrer-Policy'));

        // Without the token the same part is the live site's.
        $plain = preg_replace('#[?&]' . PreviewToken::PARAM . '=[^&]+#', '', $lazy);
        $this->assertStringContainsString('<span class="heading">Hello</span>', (string) $this->send('GET', (string) $plain, ['HX-Request' => 'true'])->getBody());
        // Signed out (another person on the link) it is refused.
        $this->assertSame(403, $this->visitor($lazy)->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_view_as_role_reaches_interactive_parts(): void
    {
        $this->signIn('admin@site.test');

        $gold = (string) $this->send('GET', '/folio/preview/page/post/p1?as=member:gold')->getBody();
        $pending = (string) $this->send('GET', '/folio/preview/page/post/p1?as=member:pending')->getBody();

        $answer = $this->send('POST', $this->url($gold, 'members', 'hx-post'), ['HX-Request' => 'true']);
        $this->assertSame(200, $answer->getStatusCode(), (string) $answer->getBody());
        $this->assertStringContainsString('welcome, member', (string) $answer->getBody(), 'a gold member\'s action');
        $this->assertSame(403, $this->send('POST', $this->url($pending, 'members', 'hx-post'), ['HX-Request' => 'true'])->getStatusCode(), 'a pending member may not');

        // A write under the token is refused, and nothing is written.
        $notes = fn (): int => count($this->session->container()->get(DataManager::class)->queryType('note')->get()->items());
        $before = $notes();
        $saved = $this->send('POST', $this->url($gold, 'save', 'hx-post'), ['HX-Request' => 'true']);
        $this->assertSame(403, $saved->getStatusCode());
        $this->assertStringContainsString('This preview does not change data.', (string) $saved->getBody());
        $this->assertSame($before, $notes());
    }

    /**
     * A lead (site.view_as, fewer rights than the admin) may view as the admin role (owner ruling,
     * 0.81); once site.view_as is taken from their role, the role view's follow-ups are refused.
     */
    #[RunInSeparateProcess]
    public function test_a_view_as_token_without_site_view_as_is_refused(): void
    {
        $this->signIn('lead@site.test');
        $preview = $this->send('GET', '/folio/preview/page/post/p1?as=member:gold');
        $this->assertSame(200, $preview->getStatusCode());
        $members = $this->url((string) $preview->getBody(), 'members', 'hx-post');
        $this->assertSame(200, $this->send('POST', $members, ['HX-Request' => 'true'])->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/folio/preview/page/post/p1?as=staff:admin')->getStatusCode(), 'also as a stronger role');

        $this->session->container()->get(RoleStore::class)->put('staff', 'lead', null, [], ['site.view_as']);

        $this->assertSame(403, $this->send('POST', $members, ['HX-Request' => 'true'])->getStatusCode(), 'site.view_as taken away ends the role view');
        $this->assertSame(403, $this->send('GET', '/folio/preview/page/post/p1?as=member:gold')->getStatusCode());
    }

    /** Two types without publishing answer at /events/party: the preview of the one a visitor would not see says so. */
    #[RunInSeparateProcess]
    public function test_another_record_at_the_address_is_explained_not_shown(): void
    {
        $this->signIn('admin@site.test');

        $event = (string) $this->send('GET', '/folio/preview/page/event/e1')->getBody();
        $party = (string) $this->send('GET', '/folio/preview/page/party/x1')->getBody();

        $shown = array_values(array_filter([$event, $party], static fn (string $b): bool => str_contains($b, '<h1>')));
        $explained = array_values(array_filter([$event, $party], static fn (string $b): bool => str_contains($b, 'Another page answers at /events/party')));
        $this->assertCount(1, $shown, 'the record the address leads to is shown');
        $this->assertCount(1, $explained, 'the other is explained');
        $this->assertStringNotContainsString('<h1>', $explained[0], 'never the other record\'s page');
    }

    /** A site whose policy forbids frames: inside the editor's frame the preview says why and offers a tab of its own. */
    #[RunInSeparateProcess]
    public function test_a_page_that_forbids_frames_is_explained_in_the_frame(): void
    {
        $this->write('config/security.php', "<?php\nreturn ['csp' => ['frame-ancestors' => [\"'none'\"]]];\n");
        $this->signIn('admin@site.test');

        $framed = $this->send('GET', '/folio/preview/page/post/p1?locale=en', ['Sec-Fetch-Dest' => 'iframe']);
        $tab = $this->send('GET', '/folio/preview/page/post/p1?locale=en', ['Sec-Fetch-Dest' => 'document']);

        $body = (string) $framed->getBody();
        $this->assertSame(200, $framed->getStatusCode());
        $this->assertStringContainsString('This site’s security settings don’t allow its pages inside a frame', $body);
        $this->assertStringContainsString('<a href="/folio/preview/page/post/p1?locale=en" target="_blank" rel="noopener">Open the preview in a new tab</a>', $body);
        $this->assertStringContainsString("frame-ancestors 'self'", $framed->getHeaderLine('Content-Security-Policy'), 'the notice itself may be framed by the admin');
        $this->assertStringContainsString('<h1>Draft</h1>', (string) $tab->getBody(), 'in a tab of its own the page shows');
    }

    /** M3: a frame-ancestors list that names only another origin refuses the admin's frame too. */
    #[RunInSeparateProcess]
    public function test_a_frame_ancestors_list_without_the_admins_origin_is_explained(): void
    {
        $this->write('config/security.php', "<?php\nreturn ['csp' => ['frame-ancestors' => ['https://cms.example']], 'headers' => ['X-Frame-Options' => 'SAMEORIGIN']];\n");
        $this->signIn('admin@site.test');

        $framed = (string) $this->send('GET', '/folio/preview/page/post/p1?locale=en', ['Sec-Fetch-Dest' => 'iframe'])->getBody();

        $this->assertStringContainsString('This site’s security settings don’t allow its pages inside a frame', $framed);
    }

    /** The token on the URL of a component request, from $html's element of class $class. */
    private function url(string $html, string $class, string $attribute): string
    {
        $this->assertSame(1, preg_match('#class="' . $class . '"[^>]*' . $attribute . '="([^"]+)"#', $html, $m), "{$class} {$attribute} in: {$html}");

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    private function tokenIn(string $html): string
    {
        $this->assertSame(1, preg_match('#' . PreviewToken::PARAM . '=([A-Za-z0-9._%-]+)#', html_entity_decode($html, ENT_QUOTES), $m), 'a token in the preview');

        return rawurldecode($m[1]);
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

    /** A visitor's request: without the editor's sign-in. */
    private function visitor(string $uri): ResponseInterface
    {
        $session = $_SESSION;
        $_SESSION = ['_flash' => ['current' => [], 'previous' => []]];
        try {
            $request = (new Psr17Factory())->createServerRequest('GET', $uri);
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

            return $this->session->handle($request->withQueryParams($query));
        } finally {
            $_SESSION = $session;
        }
    }
}

/** Site code of the test app: the member permission, and what the probe component reads and writes. */
final class PreviewFragmentsSiteProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->get(PermissionRegistry::class)->declare('members.access', 'Member area', 'Members');
    }

    public function boot(Container $container): void
    {
        PreviewProbe::$title = static fn (): string => (string) ($container->get(LiveContent::class)->find('post', 'p1')?->localized(Contexts::current()->locale)->get('title') ?? '');
        PreviewProbe::$member = static fn (): bool => $container->get(Access::class)->can('members.access');
        PreviewProbe::$write = static function () use ($container): void {
            $dm = $container->get(DataManager::class);
            $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('note'), ['uuid' => bin2hex(random_bytes(4)), 'name' => 'Saved']), false);
        };
    }
}
