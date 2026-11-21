<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context\Preview;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\SessionGuard;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Core\Context\Preview\PreviewGrant;
use Preflow\Core\Context\Preview\PreviewToken;
use Preflow\Core\Context\Preview\PreviewUrls;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Members\MembersServiceProvider;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Rbac\RbacServiceProvider;
use Preflow\Rbac\RoleStore;
use Preflow\Testing\ChecksFrameBalance;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The follow-up requests of a staff member's preview (PreviewTokenMiddleware, Task 21): a request
 * that carries the preview's signed token renders as the preview (the role or visitor, its read
 * mode), in a frame of its own, with writes refused; any token that does not hold is answered 403
 * without rendering; the page cache never serves or stores such a request; a token on an admin
 * path grants nothing.
 *
 * The site: staff (guard "staff": admin@ with every right, editor@ with pages.edit only), members
 * (guard "members"), rbac roles, a JSON "visit" type, and probe routes (PreviewRoutes) under the
 * component endpoint, on a public path and under the admin prefix /admin.
 */
final class PreviewTokenMiddlewareTest extends TestCase
{
    use ChecksFrameBalance;

    private const KEY = 'preview-token-middleware-test-key-0123456789';

    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        PreviewRoutes::$routes = [];
        PreviewRoutes::$calls = 0;
        $this->dir = sys_get_temp_dir() . '/preview_token_mw_' . bin2hex(random_bytes(4));
        foreach (['config/models', 'app/pages', 'storage/data'] as $folder) {
            @mkdir($this->dir . '/' . $folder, 0777, true);
        }
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        PreviewRoutes::$routes = [];
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_a_component_request_with_a_valid_token_renders_the_working_copy_as_the_role(): void
    {
        $app = $this->site();
        $token = $this->token($app, new RoleView('member', ['gold']));

        $response = $app->handle($this->request('/--component/probe?x=1', $token));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $seen = json_decode((string) $response->getBody(), true);
        $this->assertSame([
            'viewer' => 'member:gold',
            'reads' => 'working',
            'purpose' => 'fragment',
            'writes' => 'Refuse',
            'outbox' => 'Capture',
            'grant' => true,
            'session' => false,
            'session_of_render' => false,
            'user' => 'synthetic',
            'members' => true,
            'query' => 'x=1',
            'header' => false,
            'cookies' => false,
        ], $seen, 'the gold member\'s view of the working copies, without the editor\'s session, user or token');
        $this->assertSame(0, Contexts::depth());

        // The same request without a token is the editor's own: published reads, their user.
        $plain = json_decode((string) $app->handle($this->request('/--component/probe'))->getBody(), true);
        $this->assertSame(['id:admin@site.test', 'published', false], [$plain['viewer'], $plain['reads'], $plain['grant']]);

        // A visitor's token: anonymous, published when the preview read published.
        $visitor = json_decode((string) $app->handle($this->request('/--component/probe', $this->token($app, workingCopies: false)))->getBody(), true);
        $this->assertSame(['visitor', 'published', 'none', false], [$visitor['viewer'], $visitor['reads'], $visitor['user'], $visitor['members']]);
    }

    #[RunInSeparateProcess]
    public function test_writes_under_a_token_are_refused(): void
    {
        $app = $this->site();
        $visits = static fn (): int => count($app->container()->get(DataManager::class)->queryType('visit')->get()->items());
        $before = $visits();

        $response = $app->handle($this->request('/--component/save', $this->token($app, new RoleView('member', ['gold'])), method: 'POST'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('This preview does not change data.', (string) $response->getBody());
        $this->assertSame($before, $visits(), 'the store is unchanged');
        $this->assertSame([], $app->container()->get(TransportFactory::class)->get('mail')->sent(), 'nothing was sent');

        // Proof: without the token the same request writes.
        $this->assertSame(200, $app->handle($this->request('/--component/save', method: 'POST'))->getStatusCode());
        $this->assertSame($before + 1, $visits());
    }

    #[RunInSeparateProcess]
    #[DataProvider('rejections')]
    public function test_any_rejection_is_403_and_never_calls_the_handler(string $reason, string $case, string $key = self::KEY): void
    {
        $app = $this->site(key: $key);
        $log = $this->dir . '/php.log';
        @unlink($log);
        [$token, $change] = $this->rejected($case, $app) + [1 => []];

        $response = $app->handle($this->request('/--component/probe', $token, ...$change));

        $body = (string) $response->getBody();
        $this->assertSame(403, $response->getStatusCode(), $body);
        $this->assertSame(0, PreviewRoutes::$calls, 'the handler never ran');
        $this->assertStringContainsString('This preview is no longer available here.', $body);
        $this->assertSame(PreviewTokenMiddlewareTest::HEADERS, self::previewHeaders($response));
        $this->assertStringNotContainsString($token, $body, 'the page never shows the token');
        $logged = (string) @file_get_contents($log);
        $this->assertStringContainsString("Preview token refused: {$reason}.", $logged, 'the reason is logged');
        $this->assertStringNotContainsString($token, $logged, 'the token never is');
        $this->assertSame(0, Contexts::depth());
    }

    /** @return iterable<string, array{0: string, 1: string, 2?: string}> */
    public static function rejections(): iterable
    {
        yield 'malformed' => ['malformed', 'malformed'];
        yield 'a header and a parameter that differ' => ['malformed', 'differ'];
        yield 'signature' => ['signature', 'signature'];
        yield 'session' => ['session', 'session'];
        yield 'no session' => ['session', 'no session'];
        yield 'expired' => ['expired', 'expired'];
        yield 'identity' => ['identity', 'identity'];
        yield 'nobody signed in' => ['identity', 'nobody'];
        yield 'permission' => ['permission', 'permission'];
        yield 'view as revoked' => ['permission', 'view as'];
        yield 'disabled' => ['disabled', 'disabled', 'short-key'];
    }

    /** @return array{0: string, 1?: array<string, mixed>} the token of the case, and what it changes on the request */
    private function rejected(string $case, Application $app): array
    {
        return match ($case) {
            'malformed' => ['v1.not-a-token'],
            'differ' => [$this->token($app), ['header' => 'v1.other.x.y']],
            'signature' => [$this->token($app, signer: new PreviewToken('another-apps-key-0123456789abcdefghij'))],
            'session' => [$this->token($app, session: 'another-browsers-session')],
            'no session' => [$this->token($app), ['cookie' => false]],
            'expired' => [$this->token($app, issuedAt: new \DateTimeImmutable('-2 hours'))],
            'identity' => [$this->token($app), ['as' => 'editor@site.test']],
            'nobody' => [$this->token($app), ['as' => null]],
            // The editor holds pages.edit, but not what this token asks.
            'permission' => [$this->token($app, requires: ['members.access'], issuer: 'editor@site.test'), ['as' => 'editor@site.test']],
            // A role view needs site.view_as, which the editor does not hold.
            'view as' => [$this->token($app, new RoleView('member', ['gold']), issuer: 'editor@site.test'), ['as' => 'editor@site.test']],
            'disabled' => [$this->token($app, signer: new PreviewToken(self::KEY))],
        };
    }

    /**
     * In debug mode an undeclared permission makes Access::can() throw: the token is still answered
     * 403 (never a 500 page that would print the request and its token).
     */
    #[RunInSeparateProcess]
    public function test_a_failure_while_admitting_is_403_also_in_debug_mode(): void
    {
        $app = $this->site(debug: true);
        $token = $this->token($app, requires: ['nobody.declared.this']);

        $response = $app->handle($this->request('/--component/probe', $token));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, PreviewRoutes::$calls);
        $this->assertStringNotContainsString($token, (string) $response->getBody());
        $this->assertStringContainsString('Preview token refused: error (Preflow\Auth\Access\UndeclaredPermission).', (string) file_get_contents($this->dir . '/php.log'));
    }

    /** A page that fails under a token answers with its error page; in debug mode that page never shows the token. */
    #[RunInSeparateProcess]
    public function test_an_error_in_a_granted_render_never_shows_the_token(): void
    {
        $app = $this->site(debug: true);
        $token = $this->token($app);

        foreach (['query' => [], 'header' => ['header' => true]] as $carrier => $change) {
            $response = $app->handle($this->request('/--component/boom?keep=1', $token, ...$change));

            $body = (string) $response->getBody();
            $this->assertSame(500, $response->getStatusCode(), $carrier);
            $this->assertStringContainsString('The probe failed.', $body, 'the debug page');
            $this->assertStringContainsString('keep=1', $body, 'with the rest of the request');
            $this->assertStringNotContainsString($token, $body, "never the token ({$carrier})");
            $this->assertStringNotContainsString(PreviewToken::PARAM, $body);
            $this->assertSame(PreviewTokenMiddlewareTest::HEADERS, self::previewHeaders($response));
        }
        $this->assertSame(0, Contexts::depth());
    }

    #[RunInSeparateProcess]
    public function test_token_responses_are_never_page_cached(): void
    {
        $app = $this->site(pageCache: true);
        $token = $this->token($app, new RoleView('member', ['gold']));

        $first = $app->handle($this->request('/', $token, as: null));
        $this->assertSame(403, $first->getStatusCode(), 'signed out, the editor\'s token holds nothing');

        $previewed = $app->handle($this->request('/', $token));
        $this->assertSame(['', 'MEMBERS-ONLY'], [$previewed->getHeaderLine('X-Page-Cache'), self::main($previewed)], 'rendered, neither served nor stored');

        // A visitor (another browser: no session open) stores the public page; the preview is still never served from it.
        $visitors = $this->boot(true);
        $visitor = $visitors->handle(new ServerRequest('GET', 'https://site.test/'));
        $this->assertSame(['miss', 'PUBLIC'], [$visitor->getHeaderLine('X-Page-Cache'), self::main($visitor)]);
        $this->assertStringContainsString(PreviewToken::HEADER, $visitor->getHeaderLine('Vary'), 'a shared cache keeps it apart from a preview\'s request');
        $hit = $visitors->handle(new ServerRequest('GET', 'https://site.test/'));
        $this->assertSame(['hit', 'PUBLIC'], [$hit->getHeaderLine('X-Page-Cache'), self::main($hit)]);
        $this->assertStringContainsString(PreviewToken::HEADER, $hit->getHeaderLine('Vary'));

        foreach (['query' => [], 'header' => ['header' => true]] as $carrier => $change) {
            $again = $app->handle($this->request('/', $token, ...$change));
            $this->assertSame(['', 'MEMBERS-ONLY'], [$again->getHeaderLine('X-Page-Cache'), self::main($again)], $carrier);
        }
        $after = $visitors->handle(new ServerRequest('GET', 'https://site.test/'));
        $this->assertSame(['hit', 'PUBLIC'], [$after->getHeaderLine('X-Page-Cache'), self::main($after)], 'the visitor\'s page was never replaced by the preview');
    }

    #[RunInSeparateProcess]
    public function test_token_responses_carry_no_store_no_referrer_noindex(): void
    {
        $app = $this->site();
        PreviewRoutes::$routes['/--component/cookie'] = static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'text/plain', 'Set-Cookie' => 'role=gold; Path=/', 'Cache-Control' => 'public, max-age=600'], 'ok');

        $response = $app->handle($this->request('/--component/cookie', $this->token($app, new RoleView('member', ['gold']))));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(PreviewTokenMiddlewareTest::HEADERS, self::previewHeaders($response));
        $this->assertSame([], $response->getHeader('Set-Cookie'), 'a cookie of the granted render never reaches the editor\'s browser');
    }

    #[RunInSeparateProcess]
    public function test_a_token_on_an_admin_path_grants_nothing(): void
    {
        $app = $this->site();

        foreach ([$this->token($app, new RoleView('member', ['gold'])), 'v1.not-a-token'] as $token) {
            $response = $app->handle($this->request('/admin/probe', $token));

            $this->assertSame(200, $response->getStatusCode(), 'neither granted nor refused');
            $seen = json_decode((string) $response->getBody(), true);
            $this->assertSame(['id:admin@site.test', 'published', false, '', false], [$seen['viewer'], $seen['reads'], $seen['grant'], $seen['query'], $seen['header']], 'the admin as themselves, the token taken off');
            $this->assertSame(PreviewTokenMiddlewareTest::HEADERS, self::previewHeaders($response), 'and answered as a token request (Task 22 M1)');
        }
    }

    #[RunInSeparateProcess]
    public function test_header_and_query_carriers_are_equivalent(): void
    {
        $app = $this->site();
        $token = $this->token($app, new RoleView('member', ['gold']));

        $query = $app->handle($this->request('/--component/probe', $token));
        $header = $app->handle($this->request('/--component/probe', $token, header: true));
        $both = $app->handle($this->request('/--component/probe', $token, header: $token));

        $this->assertSame(200, $query->getStatusCode());
        $this->assertSame((string) $query->getBody(), (string) $header->getBody());
        $this->assertSame((string) $query->getBody(), (string) $both->getBody(), 'the same token in both');
        $this->assertSame(self::previewHeaders($query), self::previewHeaders($header));
    }

    /**
     * A request asked for in-process inside a granted render renders under the grant too, and the
     * component URLs it prints carry the token; outside a granted render nothing carries one.
     */
    #[RunInSeparateProcess]
    public function test_a_request_inside_a_granted_render_carries_the_token_on(): void
    {
        $app = $this->site();
        $token = $this->token($app, new RoleView('member', ['gold']));
        PreviewRoutes::$routes['/--component/outer'] = static function () use ($app): ResponseInterface {
            $inner = $app->handle(new ServerRequest('GET', 'https://site.test/--component/url'));

            return new Response(200, ['Content-Type' => 'text/plain'], (string) $inner->getBody());
        };
        PreviewRoutes::$routes['/--component/url'] = static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'text/plain'], PreviewUrls::carry('/--component/render?token=abc#top'));

        $this->assertSame('/--component/render?token=abc&_preview=' . rawurlencode($token) . '#top', (string) $app->handle($this->request('/--component/outer', $token))->getBody());
        $this->assertSame('/--component/render?token=abc#top', (string) $app->handle($this->request('/--component/outer'))->getBody(), 'never without a grant');
    }

    /**
     * An admin request that queues its own mail and renders a role view preview (core's preview(),
     * with a token): the role view's frame is its own, so the admin's mail is still delivered and the
     * page's is only captured (carried from the Task 17 re-review).
     */
    #[RunInSeparateProcess]
    public function test_a_role_preview_leaves_the_admin_requests_own_mail_to_be_sent(): void
    {
        $app = $this->site();
        $session = $this->session($app);
        $staff = $app->container()->get(AuthManager::class)->provider('staff')?->findById('admin@site.test');
        $request = (new ServerRequest('GET', 'https://site.test/admin/editor'))->withAttribute(SessionInterface::class, $session);
        $mail = $app->container()->get(TransportFactory::class)->get('mail');

        $result = Contexts::run(
            RenderContext::fromRequest($request, $app->site(), new SystemClock())->as(new Viewer(['staff' => $staff], 'staff')),
            static function () use ($app, $request): RenderResult {
                $app->container()->get(Notifier::class)->sendLater(new Email('admin@site.test', 'Saved', 'The admin saved a page.'));

                return $app->preview(Contexts::current(), '/', 'en', (new RoleView('member', ['gold']))->by('staff'), $request, ['pages.edit', 'site.view_as']);
            },
        );

        $this->assertSame('MEMBERS-ONLY', self::main($result->response));
        $this->assertSame(['Saved'], array_map(static fn (\Preflow\Notify\SentMessage $m): string => $m->message->subject, $mail->sent()), 'the admin request\'s own mail was sent, the page\'s was not');
        $this->assertSame(0, Contexts::depth());
    }

    /**
     * I1: an error raised outside the granted render (here the CSRF check, before the token is even
     * looked at) never prints the token on the debug page, and the answer is the token's: no-store,
     * no-referrer, noindex.
     */
    #[RunInSeparateProcess]
    public function test_an_error_before_the_token_is_admitted_never_shows_it(): void
    {
        $app = $this->site(debug: true);
        $token = $this->token($app);

        $response = $app->handle($this->request('/', $token, method: 'POST', header: $token, headers: ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://elsewhere.test']));

        $body = (string) $response->getBody();
        $this->assertSame(403, $response->getStatusCode(), $body);
        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString(PreviewToken::PARAM, $body);
        $this->assertStringNotContainsString(PreviewToken::HEADER, $body);
        $this->assertSame(PreviewTokenMiddlewareTest::HEADERS, self::previewHeaders($response));
    }

    /** I1: a failure around the granted render (its frame, its context) is answered with the request without the token. */
    public function test_a_failure_around_the_granted_render_never_shows_the_token(): void
    {
        $tokens = new PreviewToken(self::KEY);
        $session = new \Preflow\Testing\ArraySession();
        $session->start();
        $editor = new \Preflow\Core\Context\IdentityId('editor-1');
        $site = \Preflow\Testing\ContextFactory::forTest()->site;
        $visitor = RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/'), $site, new SystemClock())->asVisitor()->workingCopies();
        $token = Contexts::run(RenderContext::console($site, new SystemClock()), static fn (): string => $tokens->issue($visitor, $session->getId(), 'staff', $editor, ['pages.edit']));
        $gate = new class implements \Preflow\Core\Context\Preview\PreviewGate {
            public function allows(RenderContext $ctx, array $permissions): bool
            {
                return true;
            }
        };
        $middleware = new \Preflow\Core\Context\Preview\PreviewTokenMiddleware(
            tokens: $tokens,
            gate: static fn () => $gate,
            nonPublic: new NonPublicPaths(),
            render: static fn (RenderContext $ctx, \Closure $handle): ResponseInterface => throw new \LogicException('The frame could not open.'),
            errors: static fn (\Throwable $e, ServerRequestInterface $r): ResponseInterface => (new \Preflow\Core\Error\ErrorHandler(new \Preflow\Core\Error\DevErrorRenderer()))->handleException($e, $r),
        );
        $request = (new ServerRequest('GET', 'https://site.test/--component/x?a=1&' . PreviewToken::PARAM . '=' . rawurlencode($token), [PreviewToken::HEADER => $token]))
            ->withQueryParams(['a' => '1', PreviewToken::PARAM => $token])
            ->withAttribute(SessionInterface::class, $session);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('never called');
            }
        };

        $response = Contexts::run(RenderContext::fromRequest($request, $site, new SystemClock())->as(new Viewer(['staff' => $editor], 'staff')), static fn (): ResponseInterface => $middleware->process($request, $handler));

        $body = (string) $response->getBody();
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('The frame could not open.', $body);
        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString(PreviewToken::PARAM, $body);
        $this->assertSame(PreviewTokenMiddlewareTest::HEADERS, self::previewHeaders($response));
    }

    /**
     * I2: the container's session inside a granted render (and a role view) is an empty one of its
     * own: the editor's values are not read, and what the render sets or flashes never reaches the
     * editor's session.
     */
    #[RunInSeparateProcess]
    public function test_a_granted_render_never_reads_or_writes_the_editors_session(): void
    {
        $app = $this->site();
        $session = $this->session($app);
        $session->set('cart', 'editor-cart');
        $session->flash('note', 'Saved.');
        $session->ageFlash(); // as the editor's next request began: the flash is theirs to show
        PreviewRoutes::$routes['/--component/session'] = static function (ServerRequestInterface $request, Container $container): ResponseInterface {
            $s = $container->get(SessionInterface::class);
            $seen = [$s->get('cart'), $s->getFlash('note'), $s->getId()];
            $s->set('cart', 'preview-cart');
            $s->flash('note', 'From the preview.');
            $s->regenerate();
            $s->invalidate();

            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($seen));
        };
        $id = $session->getId();

        $seen = json_decode((string) $app->handle($this->request('/--component/session', $this->token($app, new RoleView('member', ['gold']))))->getBody(), true);

        $this->assertSame([null, null], array_slice($seen, 0, 2), 'nothing of the editor\'s');
        $this->assertNotSame($id, $seen[2], 'never the editor\'s session id');
        $this->assertSame('editor-cart', $session->get('cart'), 'the editor\'s value is unchanged');
        $this->assertSame('Saved.', $session->getFlash('note'), 'and their flash is still theirs');
        $this->assertSame($id, $session->getId(), 'not regenerated');

        // A whole-page role view (preview()) is fenced the same way.
        PreviewRoutes::$routes['/'] = PreviewRoutes::$routes['/--component/session'];
        $staff = $app->container()->get(AuthManager::class)->provider('staff')?->findById('admin@site.test');
        $editorRequest = (new ServerRequest('GET', 'https://site.test/admin/editor'))->withAttribute(SessionInterface::class, $session);
        $result = Contexts::run(
            RenderContext::fromRequest($editorRequest, $app->site(), new SystemClock())->as(new Viewer(['staff' => $staff], 'staff')),
            static fn (): RenderResult => $app->preview(Contexts::current(), '/', 'en', (new RoleView('member', ['gold']))->by('staff'), $editorRequest, ['pages.edit', 'site.view_as']),
        );
        $this->assertSame([null, null], array_slice(json_decode((string) $result->response->getBody(), true), 0, 2));
        $this->assertSame('editor-cart', $session->get('cart'));
        $this->assertSame('Saved.', $session->getFlash('note'));
        $this->assertSame($id, $session->getId());
    }

    /**
     * I3: on an admin or API path a token grants nothing; a GET runs as the editor, but a request
     * that would change something (a previewed page's htmx POST to an API) is refused, nothing written or sent.
     */
    #[RunInSeparateProcess]
    public function test_a_write_to_an_api_path_under_a_token_is_refused(): void
    {
        $app = $this->site();
        $token = $this->token($app, new RoleView('member', ['gold']));
        $visits = static fn (): int => count($app->container()->get(DataManager::class)->queryType('visit')->get()->items());
        $before = $visits();

        $this->session($app)->set('_csrf_token', str_repeat('c', 64)); // the editor's page passes the CSRF check
        $csrf = ['Sec-Fetch-Site' => 'same-origin', 'X-CSRF-Token' => str_repeat('c', 64)];

        $refused = $app->handle($this->request('/api/save', $token, method: 'POST', header: true, headers: $csrf));

        $this->assertSame(403, $refused->getStatusCode());
        $this->assertStringContainsString('This preview does not change data.', (string) $refused->getBody());
        $this->assertSame(PreviewTokenMiddlewareTest::HEADERS, self::previewHeaders($refused));
        $this->assertSame(0, PreviewRoutes::$calls);
        $this->assertSame($before, $visits());
        $this->assertSame([], $app->container()->get(TransportFactory::class)->get('mail')->sent());

        $this->assertSame(200, $app->handle($this->request('/api/save', method: 'POST', headers: $csrf))->getStatusCode(), 'without the token it writes');
        $this->assertSame($before + 1, $visits());
        $read = json_decode((string) $app->handle($this->request('/api/probe', $token, header: true))->getBody(), true);
        $this->assertSame(['id:admin@site.test', 'published', false, false], [$read['viewer'], $read['reads'], $read['grant'], $read['header']], 'a GET there runs as the editor, the token taken off');
    }

    /** M1: a parameter PHP reads as `_preview` (".preview") is admitted and taken off the inner request like `_preview`. */
    #[RunInSeparateProcess]
    public function test_every_spelling_of_the_parameter_is_taken_off(): void
    {
        $app = $this->site();
        $token = $this->token($app);

        foreach (['.preview', '_preview'] as $spelling) {
            $seen = json_decode((string) $app->handle($this->request('/--component/probe?x=1', $token, param: $spelling))->getBody(), true);
            $this->assertSame(['visitor', 'x=1'], [$seen['viewer'], $seen['query']], $spelling);
        }
    }

    /** M2: a public page that a cache may keep says it differs for a preview's request; a private one is left alone. */
    #[RunInSeparateProcess]
    public function test_public_pages_vary_on_the_preview_header(): void
    {
        $app = $this->site();
        PreviewRoutes::$routes['/public-page'] = static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public, max-age=600'], '<p>live</p>');
        PreviewRoutes::$routes['/private-page'] = static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private, no-store'], '<p>mine</p>');

        $this->assertContains(PreviewToken::HEADER, array_map('trim', explode(',', $app->handle(new ServerRequest('GET', 'https://site.test/public-page'))->getHeaderLine('Vary'))));
        $this->assertStringNotContainsString(PreviewToken::HEADER, $app->handle(new ServerRequest('GET', 'https://site.test/private-page'))->getHeaderLine('Vary'));
        $this->assertStringNotContainsString(PreviewToken::HEADER, $app->handle(new ServerRequest('GET', 'https://site.test/admin/probe'))->getHeaderLine('Vary'), 'not on the admin');
    }

    /** Task 22 I1: an admitted page is kept out of htmx's history cache, on the same <body> that carries the token. */
    #[RunInSeparateProcess]
    public function test_a_granted_page_is_kept_out_of_the_client_history_cache(): void
    {
        $app = $this->site();
        $token = $this->token($app, new RoleView('member', ['gold']));

        $page = (string) $app->handle($this->request('/', $token))->getBody();

        $this->assertMatchesRegularExpression('#<body hx-headers="[^"]+" hx-history="false">#', $page);
        $this->assertStringContainsString('<body><main>', (string) $app->handle(new ServerRequest('GET', 'https://site.test/'))->getBody(), 'a visitor\'s page is left alone');
    }

    /**
     * Task 22 M1: every answer to a request with a token is the token's (no-store, no-referrer,
     * noindex), also one a middleware gives before the token is read: the clean-path redirect's
     * public max-age is replaced.
     */
    #[RunInSeparateProcess]
    public function test_every_answer_to_a_token_request_is_no_store_even_from_outer_middleware(): void
    {
        $this->write('config/routing.php', "<?php\nreturn ['canonical_paths' => true];\n");
        $app = $this->site();
        $token = $this->token($app);

        $redirect = $app->handle($this->request('/probe/', $token));
        $plain = $app->handle(new ServerRequest('GET', 'https://site.test/probe/'));

        $this->assertSame(301, $redirect->getStatusCode());
        $this->assertSame(PreviewTokenMiddlewareTest::HEADERS, self::previewHeaders($redirect));
        $this->assertSame(301, $plain->getStatusCode());
        $this->assertNotSame('no-referrer', $plain->getHeaderLine('Referrer-Policy'), 'proof: without a token the redirect is an ordinary one');
        $admin = $app->handle($this->request('/admin/probe', $token));
        $this->assertSame(PreviewTokenMiddlewareTest::HEADERS, self::previewHeaders($admin), 'an admin answer to a token request too');
    }

    /** Task 22 M6: a private page that the browser may keep still says it differs for a preview's request. */
    #[RunInSeparateProcess]
    public function test_a_private_cacheable_page_still_varies_on_the_preview_header(): void
    {
        $app = $this->site();
        PreviewRoutes::$routes['/mine'] = static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private, max-age=600'], '<p>mine</p>');

        $this->assertContains(PreviewToken::HEADER, array_map('trim', explode(',', $app->handle(new ServerRequest('GET', 'https://site.test/mine'))->getHeaderLine('Vary'))));
    }

    /** The headers a token's answer must carry. */
    public const array HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex'];

    /** @return array<string, string> */
    private static function previewHeaders(ResponseInterface $response): array
    {
        return array_map(static fn (string $name): string => $response->getHeaderLine($name), array_combine(array_keys(self::HEADERS), array_keys(self::HEADERS)));
    }

    /**
     * A token of the editor's preview in the app's session (else $session), issued to $issuer for
     * $requires (a role view adds site.view_as), at $issuedAt (now when null), by $signer (the app's).
     *
     * @param list<string> $requires
     */
    public function token(
        Application $app,
        ?RoleView $as = null,
        array $requires = ['pages.edit'],
        string $issuer = 'admin@site.test',
        ?string $session = null,
        ?\DateTimeImmutable $issuedAt = null,
        bool $workingCopies = true,
        ?PreviewToken $signer = null,
    ): string {
        $user = $app->container()->get(AuthManager::class)->provider('staff')?->findById($issuer) ?? throw new \RuntimeException("no {$issuer}");
        $ctx = RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/'), $app->site(), new SystemClock())->asVisitor()->because(Purpose::Preview);
        $ctx = $workingCopies ? $ctx->workingCopies() : $ctx->published();
        if ($as !== null) {
            $ctx = $ctx->asRole($as->by('staff'));
            $requires[] = 'site.view_as';
        }
        $session ??= $this->session($app)->getId();
        $signer ??= $app->container()->get(PreviewToken::class);
        $clock = $issuedAt === null ? new SystemClock() : new FrozenClock($issuedAt);

        return Contexts::run(RenderContext::console($app->site(), $clock), static fn (): string => $signer->issue($ctx, $session, 'staff', $user, $requires));
    }

    /**
     * A browser request of the editor's: their session cookie (cookie false: none), signed in as $as
     * (null: nobody) with the staff guard, the token as `_preview` and, with $header, as the header
     * too (true: the token; a string: that value; the parameter then only when not true).
     */
    /** @param array<string, string> $headers more request headers (they replace Sec-Fetch-Site: same-origin) */
    private function request(string $uri, ?string $token = null, string $method = 'GET', bool|string $header = false, bool $cookie = true, ?string $as = 'admin@site.test', array $headers = ['Sec-Fetch-Site' => 'same-origin'], string $param = PreviewToken::PARAM): ServerRequestInterface
    {
        $app = $this->app ?? throw new \LogicException('site() first');
        if ($token !== null && $header !== true) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . $param . '=' . rawurlencode($token);
        }
        $request = new ServerRequest($method, 'https://site.test' . $uri, $headers + ['Authorization' => 'Bearer editors-own']);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($header !== false && $token !== null) {
            $request = $request->withHeader(PreviewToken::HEADER, $header === true ? $token : $header);
        }
        if ($cookie) {
            $request = $request->withCookieParams(['pvt' => $this->session($app)->getId()]);
        }
        if ($as !== null) {
            $user = $app->container()->get(AuthManager::class)->provider('staff')?->findById($as) ?? throw new \RuntimeException("no {$as}");
            $request = $request->withAttribute(Authenticatable::class, $user)->withAttribute(AuthManager::GUARD_ATTRIBUTE, 'staff');
        }

        return $request;
    }

    private function session(Application $app): SessionInterface
    {
        $session = $app->container()->get(SessionInterface::class);
        $session->start();

        return $session;
    }

    private ?Application $app = null;

    private function site(string $key = self::KEY, bool $debug = false, bool $pageCache = false): Application
    {
        $this->write('config/app.php', '<?php return ' . var_export(['name' => 'preview', 'debug' => $debug ? 1 : 0, 'engine' => 'twig', 'key' => $key, 'url' => 'https://site.test', 'locale' => 'en'], true) . ";\n");
        $this->write('config/auth.php', '<?php return ' . var_export([
            'default_guard' => 'staff',
            'guards' => [
                'staff' => ['class' => SessionGuard::class, 'provider' => 'staff', 'population' => 'staff'],
                'members' => ['class' => SessionGuard::class, 'provider' => 'members', 'population' => 'members'],
            ],
            'providers' => [
                'staff' => ['class' => ConfigUserProvider::class, 'users' => [
                    ['id' => 'admin-1', 'email' => 'admin@site.test', 'password_hash' => '', 'roles' => ['admin']],
                    ['id' => 'editor-2', 'email' => 'editor@site.test', 'password_hash' => '', 'roles' => ['editor']],
                ]],
                'members' => ['class' => ConfigUserProvider::class, 'users' => [
                    ['id' => 'm-1', 'email' => 'ada@site.test', 'password_hash' => '', 'roles' => ['member']],
                ]],
            ],
            'session' => ['cookie' => 'pvt', 'secure' => false, 'paths' => ['/admin']],
        ], true) . ";\n");
        $this->write('config/data.php', '<?php return ' . var_export([
            'default' => 'sqlite',
            'models_path' => $this->dir . '/config/models',
            'drivers' => [
                'sqlite' => ['driver' => \Preflow\Data\Driver\SqliteDriver::class, 'path' => $this->dir . '/storage/site.sqlite'],
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
            ],
        ], true) . ";\n");
        $this->write('config/models/visit.json', (string) json_encode([
            'key' => 'visit', 'table' => 'visit', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Visits',
            'fields' => ['path' => ['type' => 'string']],
        ]));
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
        $this->write('config/providers.php', "<?php\nreturn [" . var_export(RbacServiceProvider::class, true) . ', ' . var_export(MembersServiceProvider::class, true) . ', ' . var_export(PreviewSiteProvider::class, true) . "];\n");
        $this->write('config/middleware.php', "<?php\nreturn [" . var_export(PreviewRoutes::class, true) . "];\n");
        if ($pageCache) {
            $this->write('config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true, 'verify' => 'off'];\n");
        }
        $this->write('app/pages/index.twig', '<html><head><title>Home</title></head><body><main>{% if can("members.access") %}MEMBERS-ONLY{% else %}PUBLIC{% endif %}</main></body></html>');

        $app = $this->boot($pageCache);
        $store = $app->container()->get(RoleStore::class);
        if (!$store->installed()) {
            $store->install();
            $store->seed([
                'staff' => ['admin' => ['*'], 'editor' => ['pages.edit']],
                'members' => ['member' => ['members.access'], 'gold' => ['members.access'], 'pending' => []],
            ]);
        }
        PreviewRoutes::probes();

        return $this->app = $app;
    }

    /** Another app on the same site (another browser's process: no session of the editor's open). */
    private function boot(bool $pageCache): Application
    {
        $app = Application::create($this->dir);
        if ($pageCache) {
            $app->setRunningInConsole(false);
            $app->runAfterResponseInHandle();
        }
        $app->boot();

        return $app;
    }

    private static function main(ResponseInterface $response): string
    {
        $body = (string) $response->getBody();

        return preg_match('~<main>(.*?)</main>~s', $body, $m) === 1 ? $m[1] : $body;
    }

    private function write(string $rel, string $content): void
    {
        @mkdir(dirname($this->dir . '/' . $rel), 0777, true);
        file_put_contents($this->dir . '/' . $rel, $content);
    }
}

/** Site code of the test app: its permission, and the admin prefix (as an admin package registers it). */
final class PreviewSiteProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->get(PermissionRegistry::class)->declare('pages.edit', 'Edit pages', 'Pages');
        $container->get(NonPublicPaths::class)->add('/admin');
        $container->get(NonPublicPaths::class)->add('/api');
    }
}

/** Answers the paths a test registers (after the core middleware, as a site's routes), else passes the request on. */
final class PreviewRoutes implements MiddlewareInterface
{
    /** @var array<string, \Closure(ServerRequestInterface, Container): ResponseInterface> */
    public static array $routes = [];

    /** How many times a route ran. */
    public static int $calls = 0;

    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = self::$routes[$request->getUri()->getPath()] ?? null;
        if ($route === null) {
            return $handler->handle($request);
        }
        self::$calls++;

        return $route($request, $this->container);
    }

    /** The probes: what the request renders as, a write, a failure. */
    public static function probes(): void
    {
        $probe = static function (ServerRequestInterface $request, Container $container): ResponseInterface {
            $ctx = Contexts::current();
            $user = $request->getAttribute(Authenticatable::class);

            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'viewer' => $ctx->viewer->roleView !== null ? $ctx->viewer->roleView->population . ':' . implode(',', $ctx->viewer->roleView->roles) : ($ctx->viewer->isAnonymous() ? 'visitor' : 'id:' . $ctx->viewer->primary()?->getAuthId()),
                'reads' => $ctx->reads->readsWorkingCopies() ? 'working' : 'published',
                'purpose' => $ctx->purpose->value,
                'writes' => $ctx->writes->name,
                'outbox' => $ctx->outbox->name,
                'grant' => $ctx->get(PreviewGrant::class) !== null,
                'session' => $request->getAttribute(SessionInterface::class) !== null,
                'session_of_render' => SessionMiddleware::ofCurrentRender() !== null,
                'user' => $user === null ? 'none' : ($user instanceof \Preflow\Auth\SyntheticUser ? 'synthetic' : 'id:' . $user->getAuthId()),
                'members' => $container->get(Access::class)->can('members.access'),
                'query' => $request->getUri()->getQuery(),
                'header' => $request->hasHeader(PreviewToken::HEADER),
                'cookies' => $request->getCookieParams() !== [] || $request->hasHeader('Cookie') || $request->hasHeader('Authorization'),
            ]));
        };
        self::$routes['/--component/probe'] = $probe;
        self::$routes['/admin/probe'] = $probe;
        self::$routes['/api/probe'] = $probe;
        self::$routes['/api/save'] = self::$routes['/--component/save'] = static function (ServerRequestInterface $request, Container $container): ResponseInterface {
            $dm = $container->get(DataManager::class);
            $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('visit'), ['uuid' => bin2hex(random_bytes(4)), 'path' => '/']), false);
            $container->get(Notifier::class)->sendLater(new Email('owner@site.test', 'Visited', 'Someone saved.'));

            return new Response(200, ['Content-Type' => 'text/plain'], 'saved');
        };
        self::$routes['/--component/boom'] = static fn (): ResponseInterface => throw new \RuntimeException('The probe failed.');
    }
}
