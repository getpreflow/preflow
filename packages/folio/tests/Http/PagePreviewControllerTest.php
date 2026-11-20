<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\CookieQueue;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Rbac\RoleStore;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;
use Psr\Http\Message\ResponseInterface;

/**
 * Folio's whole-page preview (GET /folio/preview/page/{type}/{id}?locale=&as=), over a site with
 * rbac: the post p1 published as "Hello"/"Hallo", its working copy "Draft"/"Entwurf". Its page
 * shows a member-only block (can("members.access")), whether someone is signed in, and an inline
 * script with the render's nonce; every render of it writes a note, queues a mail and a cookie.
 *
 * Staff: admin ("*"), lead (edits posts, may view as roles: site.view_as), writer (edits posts).
 * Member roles: member, gold (both members.access), pending (nothing).
 */
final class PagePreviewControllerTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private ?Application $session = null;
    private string $token = '';

    protected function setUp(): void
    {
        Contexts::reset();
        $this->scaffoldPublishing();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Preview', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-page-preview-test-key-0123', 'url' => 'https://site.test'];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><head><title>{{ record.title }}</title></head><body><h1>{{ record.title }}</h1>'
            . '{% if can("members.access") %}<b class="members">MEMBERS-ONLY</b>{% endif %}<p class="who">{{ auth_check() ? "in" : "out" }}</p>'
            . '{{ page_side_effects() }}<script nonce="{{ csp_nonce() }}">window.previewed = true;</script></body></html>');
        ini_set('error_log', $this->dir . '/php.log');
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, " . var_export(PagePreviewSiteProvider::class, true) . "];\n");

        $store = $this->app()->container()->get(RoleStore::class);
        $store->install();
        $store->seed([
            'staff' => ['admin' => ['*'], 'lead' => ['post.view', 'post.edit', 'post.create', 'site.view_as'], 'writer' => ['post.view', 'post.edit', 'post.create']],
            'members' => ['member' => ['members.access'], 'gold' => ['members.access'], 'pending' => []],
        ]);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de']]), null);
        $this->working('post', 'p1', ['title' => ['en' => 'Draft', 'de' => 'Entwurf'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de']]);
        $this->working('note', 'n1', ['name' => 'A note']);
        // Staff sign in from here on (an app with auth starts its session when it boots).
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'lead@site.test', 'password_hash' => $hash, 'roles' => ['lead']],
                ['email' => 'writer@site.test', 'password_hash' => $hash, 'roles' => ['writer']],
            ]]],
            'session' => ['cookie' => 'folio_page_preview_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_preview_renders_the_records_route_with_working_copies(): void
    {
        $this->signIn('admin@site.test');

        $preview = $this->send('GET', '/folio/preview/page/post/p1?locale=de');

        $body = (string) $preview->getBody();
        $this->assertSame(200, $preview->getStatusCode(), $body);
        $this->assertStringContainsString('<title>Entwurf</title>', $body, 'the page in German, from the working copy');
        $this->assertStringContainsString('<h1>Entwurf</h1>', $body);
        $this->assertStringContainsString('<p class="who">out</p>', $body, 'as a visitor: the signed-in editor is not who the page renders for');
        $this->assertStringNotContainsString('MEMBERS-ONLY', $body);
        $this->assertStringContainsString('preview-editor.js', $body, 'the preview-editor bridge');
        $this->assertStringContainsString('preview-editor.css', $body);
        $this->assertSame('private, no-store', $preview->getHeaderLine('Cache-Control'));
        $this->assertSame('noindex', $preview->getHeaderLine('X-Robots-Tag'));

        // The page's own response: its policy holds the nonce of its inline script.
        $this->assertSame(1, preg_match('/<script nonce="([^"]+)">window\.previewed/', $body, $script), $body);
        $this->assertStringContainsString("'nonce-{$script[1]}'", $preview->getHeaderLine('Content-Security-Policy'));
        preg_match_all('#nonce="([A-Za-z0-9+/=]{16,})"#', $body, $all);
        $this->assertSame([$script[1]], array_values(array_unique($all[1])), 'every nonce in the page is its own');

        // The English page, and the visitor still gets the published one.
        $this->assertStringContainsString('<h1>Draft</h1>', (string) $this->send('GET', '/folio/preview/page/post/p1?locale=en')->getBody());
        $this->assertStringContainsString('<h1>Hallo</h1>', (string) $this->visitor('/de/hallo')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_preview_bar_reports_mail_and_writes(): void
    {
        $this->signIn('admin@site.test');
        $notes = fn (): int => count($this->session->container()->get(DataManager::class)->queryType('note')->get()->items());
        $before = $notes();

        $body = (string) $this->send('GET', '/folio/preview/page/post/p1?locale=en')->getBody();

        $this->assertMatchesRegularExpression('#<aside class="folio-pp-bar"[^>]*>.*This page would send 1 mail<.*This page would write 1 record<.*Plain links and forms open the live site\.#s', $body);
        $this->assertSame($before, $notes(), 'nothing was written');
        $this->assertSame([], $this->session->container()->get(TransportFactory::class)->get('mail')->sent(), 'nothing was sent');
        $this->assertSame(0, $this->session->container()->get(Notifier::class)->flushDeferred(), 'nothing waits to be sent');
    }

    #[RunInSeparateProcess]
    public function test_view_as_needs_site_view_as(): void
    {
        $this->signIn('writer@site.test');
        $this->assertSame(200, $this->send('GET', '/folio/preview/page/post/p1?locale=en')->getStatusCode(), 'the preview itself needs post.edit only');
        $this->assertSame(403, $this->send('GET', '/folio/preview/page/post/p1?locale=en&as=member:gold')->getStatusCode(), 'view as needs site.view_as');
        $this->assertSame(403, $this->send('GET', '/folio/preview/page/post/p1?locale=en&as=nonsense')->getStatusCode());
        $this->assertSame(0, Contexts::depth());
    }

    #[RunInSeparateProcess]
    public function test_view_as_shows_what_the_role_sees(): void
    {
        $this->signIn('admin@site.test');

        $gold = (string) $this->send('GET', '/folio/preview/page/post/p1?locale=en&as=member:gold')->getBody();
        $pending = (string) $this->send('GET', '/folio/preview/page/post/p1?locale=en&as=member:pending')->getBody();

        $this->assertStringContainsString('<b class="members">MEMBERS-ONLY</b>', $gold, 'a gold member sees the member-only block');
        $this->assertStringContainsString('<p class="who">in</p>', $gold);
        $this->assertStringContainsString('<h1>Draft</h1>', $gold, 'of the working copy too');
        $this->assertStringNotContainsString('MEMBERS-ONLY', $pending);
        $this->assertSame(200, $this->send('GET', '/folio/preview/page/post/p1?locale=en&as=staff:admin')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_view_as_lists_member_roles_and_staff_roles_the_editor_may_see(): void
    {
        $this->signIn('lead@site.test');
        $form = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();

        $this->assertSame(1, preg_match('#<select data-preview-as>(.*?)</select>#s', $form, $select), 'the View as control');
        preg_match_all('#<option value="([^"]*)">([^<]*)</option>#', $select[1], $options);
        $this->assertSame(['', 'member:gold', 'member:member', 'member:pending', 'staff:lead', 'staff:writer'], $options[1], 'member roles, and staff roles with no more rights than the lead\'s own');
        $this->assertSame('Visitor (not signed in)', $options[2][0]);
        $this->assertStringContainsString('<optgroup label="Member roles">', $select[1]);
        $this->assertStringContainsString('<optgroup label="Staff roles">', $select[1]);
        $this->assertSame(403, $this->send('GET', '/folio/preview/page/post/p1?locale=en&as=staff:admin')->getStatusCode(), 'never as a role with more rights');
        $this->assertSame(200, $this->send('GET', '/folio/preview/page/post/p1?locale=en&as=staff:writer')->getStatusCode());

        // Without site.view_as there is nothing to choose.
        $this->signIn('writer@site.test');
        $this->assertStringNotContainsString('data-preview-as', (string) $this->send('GET', '/folio/post/p1/edit')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_new_records_and_routeless_types_offer_the_live_preview_only(): void
    {
        $this->signIn('admin@site.test');

        $saved = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();
        $this->assertStringContainsString('data-page-preview-url="/folio/preview/page/post/p1?locale=en"', $saved);
        $this->assertStringContainsString('<button type="button" data-preview-tab="page" aria-pressed="false">Whole page</button>', $saved);
        $this->assertStringContainsString('class="folio-preview-frame folio-page-frame"', $saved);
        $this->assertStringContainsString('page-preview.js', $saved);

        $new = (string) $this->send('GET', '/folio/post/new')->getBody();
        $this->assertStringContainsString('data-page-preview', $new);
        $this->assertStringNotContainsString('data-page-preview-url', $new, 'a new record has no page yet');
        $this->assertStringContainsString('data-preview-tab="page" aria-pressed="false" disabled title="Save once to preview the whole page"', $new);
        $this->assertStringNotContainsString('folio-page-frame', $new);

        // A type without a route has no page: neither the tab nor the route.
        $note = (string) $this->send('GET', '/folio/note/n1/edit')->getBody();
        $this->assertStringNotContainsString('data-preview-tab', $note);
        $this->assertSame(404, $this->send('GET', '/folio/preview/page/note/n1')->getStatusCode());
        $this->assertSame(404, $this->send('GET', '/folio/preview/page/post/missing')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_preview_leaves_the_admin_frame_untouched(): void
    {
        $this->signIn('admin@site.test');
        $this->session->runAfterResponseInHandle();

        $preview = $this->send('GET', '/folio/preview/page/post/p1?locale=de&as=member:gold');

        $this->assertSame(200, $preview->getStatusCode());
        $this->assertSame([], array_values(array_filter($preview->getHeader('Set-Cookie'), static fn (string $c): bool => str_starts_with($c, 'probe='))), 'the page\'s cookie stays in its frame');
        foreach (array_keys($preview->getHeaders()) as $name) {
            $this->assertStringStartsNotWith('hx-', strtolower($name), 'no hypermedia header of the page');
        }
        $this->assertSame(0, Contexts::depth());
        $this->assertSame([], $this->session->container()->get(TransportFactory::class)->get('mail')->sent(), 'the page\'s mail is not sent with the admin\'s request');

        // The admin is still who they were, in their own session.
        $form = $this->send('GET', '/folio/post/p1/edit');
        $this->assertSame(200, $form->getStatusCode(), 'still signed in');
        $this->assertStringContainsString('<p class="who">out</p>', (string) $this->visitor('/hello')->getBody(), 'the next visitor is nobody');
        $this->assertStringContainsString('<h1>Hello</h1>', (string) $this->visitor('/hello')->getBody(), 'reading the published page');
    }

    /** A page that fails shows its own error page in the preview; the admin's request is unharmed. */
    #[RunInSeparateProcess]
    public function test_an_error_in_the_page_stays_in_the_preview(): void
    {
        $this->signIn('admin@site.test');
        PagePreviewSiteProvider::$fail = true;

        $preview = $this->send('GET', '/folio/preview/page/post/p1?locale=en');

        $this->assertSame(500, $preview->getStatusCode());
        $this->assertSame('private, no-store', $preview->getHeaderLine('Cache-Control'));
        $this->assertSame(0, Contexts::depth());
        PagePreviewSiteProvider::$fail = false;
        $this->assertSame(200, $this->send('GET', '/folio/post/p1/edit')->getStatusCode(), 'still signed in');
    }

    private function signIn(string $email): void
    {
        $f = new Psr17Factory();
        if ($this->session !== null) {
            $out = $this->session->handle($f->createServerRequest('POST', '/folio/logout')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody(['_csrf_token' => $this->token]));
            $this->assertSame(302, $out->getStatusCode(), 'signed out first');
        }
        $this->session ??= $this->app();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => $email, 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    private function send(string $method, string $uri): ResponseInterface
    {
        return $this->session->handle((new Psr17Factory())->createServerRequest($method, $uri));
    }

    /** A visitor's request: another browser, without the admin's session cookie. */
    private function visitor(string $uri): ResponseInterface
    {
        $session = $_SESSION;
        $_SESSION = ['_flash' => ['current' => [], 'previous' => []]];
        try {
            return $this->session->handle((new Psr17Factory())->createServerRequest('GET', $uri));
        } finally {
            $_SESSION = $session;
        }
    }
}

/**
 * Site code of the test app: the member-only permission its page asks, and a render that has
 * side effects (a note written, a mail and a cookie queued), or fails when told to.
 */
final class PagePreviewSiteProvider extends ServiceProvider
{
    public static bool $fail = false;

    public function register(Container $container): void
    {
        $container->get(PermissionRegistry::class)->declare('members.access', 'Member area', 'Members');
    }

    public function boot(Container $container): void
    {
        $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
            name: 'page_side_effects',
            callable: static function () use ($container): string {
                if (self::$fail) {
                    throw new \RuntimeException('The page failed.');
                }
                $dm = $container->get(DataManager::class);
                $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('note'), ['uuid' => bin2hex(random_bytes(4)), 'name' => 'Visited']), false);
                $container->get(Notifier::class)->sendLater(new Email('owner@site.test', 'Visited', 'Someone read the page.'));
                $container->get(CookieQueue::class)->queue('probe=1; Path=/');

                return '';
            },
        ));
    }
}
