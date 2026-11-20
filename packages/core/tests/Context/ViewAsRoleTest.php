<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\AuthManager;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\SessionGuard;
use Preflow\Auth\SyntheticUser;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\OutboxPolicy;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Context\WritePolicy;
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
 * View as role, the core API any app can call: Application::render() under a context with a role
 * view (asRole()). Over a site with rbac and the members core: the page shows what the role may
 * see, can() answers from the role's grants and nobody's account, a write is skipped and
 * reported, mail is captured, nothing is stored in the page cache, and the next request is the
 * visitor's own again.
 */
final class ViewAsRoleTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        ViewAsRoutes::$routes = [];
        $this->dir = sys_get_temp_dir() . '/view_as_role_' . bin2hex(random_bytes(4));
        foreach (['config/models', 'app/pages', 'storage/data'] as $folder) {
            @mkdir($this->dir . '/' . $folder, 0777, true);
        }
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        ViewAsRoutes::$routes = [];
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function site(bool $pageCache = false): Application
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'viewas', 'debug' => 0, 'engine' => 'twig', 'key' => 'view-as-role-test-key-0123456789abcdef', 'url' => 'https://site.test', 'locale' => 'en'];\n");
        // The staff guard is named "staff", as a population is: a role view must never read as that guard.
        $this->write('config/auth.php', '<?php return ' . var_export([
            'default_guard' => 'staff',
            'guards' => [
                'staff' => ['class' => SessionGuard::class, 'provider' => 'staff', 'population' => 'staff'],
                'members' => ['class' => SessionGuard::class, 'provider' => 'members', 'population' => 'members'],
            ],
            'providers' => [
                'staff' => ['class' => ConfigUserProvider::class, 'users' => [
                    ['id' => 'admin-1', 'email' => 'admin@site.test', 'password_hash' => '', 'roles' => ['admin']],
                ]],
                'members' => ['class' => ConfigUserProvider::class, 'users' => [
                    ['id' => 'm-1', 'email' => 'ada@site.test', 'password_hash' => '', 'roles' => ['member']],
                ]],
            ],
            'session' => ['cookie' => 'viewas', 'secure' => false, 'paths' => ['/admin']],
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
        $this->write('config/providers.php', "<?php\nreturn [" . var_export(RbacServiceProvider::class, true) . ', ' . var_export(MembersServiceProvider::class, true) . ', ' . var_export(ViewAsProvider::class, true) . "];\n");
        $this->write('config/middleware.php', "<?php\nreturn [" . var_export(ViewAsRoutes::class, true) . "];\n");
        if ($pageCache) {
            $this->write('config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true, 'verify' => 'off'];\n");
        }
        $this->write(
            'app/pages/index.twig',
            '<html><head><title>Home</title></head><body><main>{% if can("members.access") %}MEMBERS-ONLY{% else %}PUBLIC{% endif %}|{{ auth_check() ? "in" : "out" }}</main></body></html>',
        );

        $app = Application::create($this->dir);
        if ($pageCache) {
            $app->setRunningInConsole(false);
            $app->runAfterResponseInHandle();
        }
        $app->boot();

        $store = $app->container()->get(RoleStore::class);
        if (!$store->installed()) {
            $store->install();
            $store->seed([
                'staff' => ['editor' => ['pages.edit']],
                'members' => ['member' => ['members.access'], 'gold' => ['members.access'], 'pending' => []],
            ]);
        }

        return $app;
    }

    private function write(string $rel, string $content): void
    {
        @mkdir(dirname($this->dir . '/' . $rel), 0777, true);
        file_put_contents($this->dir . '/' . $rel, $content);
    }

    /** The preview of $request as $view: what Folio's view-as control builds (core API). */
    private static function asRole(Application $app, ServerRequestInterface $request, RoleView $view): RenderContext
    {
        return RenderContext::fromRequest($request, $app->site(), new SystemClock())->asVisitor()->asRole($view)->because(Purpose::Preview);
    }

    /** An admin's own request: the staff guard's admin signed in. */
    private static function admin(Application $app, ServerRequestInterface $request): RenderContext
    {
        $admin = $app->container()->get(AuthManager::class)->provider('staff')?->findById('admin@site.test') ?? throw new \RuntimeException('no admin');

        return RenderContext::fromRequest($request, $app->site(), new SystemClock())->as(new Viewer(['staff' => $admin], 'staff'));
    }

    private static function main(ResponseInterface $response): string
    {
        $body = (string) $response->getBody();

        return preg_match('~<main>(.*?)</main>~s', $body, $m) === 1 ? $m[1] : $body;
    }

    #[RunInSeparateProcess]
    public function test_a_member_only_block_shows_for_the_member_role_and_not_for_anonymous(): void
    {
        $app = $this->site();
        $request = new ServerRequest('GET', 'https://site.test/');

        $this->assertSame('MEMBERS-ONLY|in', self::main($app->render(self::asRole($app, $request, new RoleView('member', ['gold'])), $request)->response));
        $this->assertSame('MEMBERS-ONLY|in', self::main($app->render(self::asRole($app, $request, new RoleView('member', ['member'])), $request)->response));
        $this->assertSame('PUBLIC|in', self::main($app->render(self::asRole($app, $request, new RoleView('member', ['pending'])), $request)->response), 'signed in as a role without the grant');
        $this->assertSame('PUBLIC|out', self::main($app->render(RenderContext::fromRequest($request, $app->site(), new SystemClock())->asVisitor()->because(Purpose::Preview), $request)->response), 'an anonymous visitor');
        // A staff role holds no member permission: members.access is not among its grants.
        $this->assertSame('PUBLIC|in', self::main($app->render(self::asRole($app, $request, new RoleView('staff', ['editor'])), $request)->response));

        // Started from an admin's own request, the role view still shows the role's page.
        $html = Contexts::run(self::admin($app, $request), static fn (): string => self::main($app->render(self::asRole($app, $request, new RoleView('member', ['gold'])), $request)->response));
        $this->assertSame('MEMBERS-ONLY|in', $html);
    }

    #[RunInSeparateProcess]
    public function test_can_answers_from_the_role_grants(): void
    {
        $app = $this->site();
        $access = $app->container()->get(Access::class);
        $auth = $app->container()->get(AuthManager::class);
        $request = new ServerRequest('GET', 'https://site.test/');
        $ask = static fn (): array => [
            'pages.edit' => $access->can('pages.edit'),
            'site.view_as' => $access->can('site.view_as'),
            'members.access' => $access->can('members.access'),
            'any site.view_as' => $access->canAny('site.view_as'),
            'population' => $access->population(),
        ];

        // The admin's own request (roles ['admin'], everything) around the role view.
        [$outer, $inner, $user, $guard, $after] = Contexts::run(self::admin($app, $request), static function () use ($app, $request, $ask, $auth): array {
            $outer = $ask();
            [$inner, $user, $guard] = Contexts::run(self::asRole($app, $request, new RoleView('staff', ['editor'])), static fn (): array => [$ask(), $auth->user(), $auth->guardName()]);

            return [$outer, $inner, $user, $guard, $ask()];
        });

        $this->assertSame(['pages.edit' => true, 'site.view_as' => true, 'members.access' => true, 'any site.view_as' => true, 'population' => 'staff'], $outer, 'the admin ("*") may view the site as a role');
        $this->assertSame(['pages.edit' => true, 'site.view_as' => false, 'members.access' => false, 'any site.view_as' => false, 'population' => 'staff'], $inner, 'the editor role\'s own grants, never the admin\'s');
        $this->assertInstanceOf(SyntheticUser::class, $user);
        $this->assertNotSame('staff', $guard, 'a role view\'s population never reads as a guard name');
        $this->assertSame($outer, $after, 'the admin\'s answers are back after the role view');

        // A member role: its own grants in the members population.
        $member = Contexts::run(self::asRole($app, $request, new RoleView('member', ['gold'])), $ask);
        $this->assertSame(['pages.edit' => false, 'site.view_as' => false, 'members.access' => true, 'any site.view_as' => false, 'population' => 'members'], $member);
    }

    #[RunInSeparateProcess]
    public function test_a_write_on_get_is_skipped_and_reported_under_a_role(): void
    {
        $app = $this->site();
        ViewAsRoutes::$routes['/count'] = static function (ServerRequestInterface $request, Container $c): ResponseInterface {
            $dm = $c->get(DataManager::class);
            $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('visit'), ['uuid' => 'v-' . bin2hex(random_bytes(3)), 'path' => '/count']));

            return new Response(200, ['Content-Type' => 'text/plain'], 'counted ' . count($dm->queryType('visit')->get()->items()));
        };
        $request = new ServerRequest('GET', 'https://site.test/count');
        $ctx = self::asRole($app, $request, new RoleView('member', ['gold']));
        $this->assertSame([WritePolicy::Skip, OutboxPolicy::Capture], [$ctx->writes, $ctx->outbox]);

        $result = $app->render($ctx, $request);

        $this->assertSame(200, $result->response->getStatusCode());
        $this->assertSame('counted 0', (string) $result->response->getBody(), 'the page renders as a visit would, the store unchanged');
        $this->assertSame(1, $result->wouldWrite(), 'the write is reported');
        $this->assertSame('visit', $result->skippedWrites[0]->type ?? null);
        $this->assertSame([], $app->container()->get(DataManager::class)->queryType('visit')->get()->items(), 'nothing was written');

        // A visitor's own request writes.
        $this->assertSame('counted 1', (string) $app->handle($request)->getBody());
    }

    #[RunInSeparateProcess]
    public function test_mail_queued_under_a_role_is_captured_not_sent(): void
    {
        $app = $this->site();
        ViewAsRoutes::$routes['/welcome'] = static function (ServerRequestInterface $request, Container $c): ResponseInterface {
            $c->get(Notifier::class)->sendLater(new Email('ada@site.test', 'Welcome', 'Your account is ready.'));

            return new Response(200, ['Content-Type' => 'text/plain'], 'queued');
        };
        $request = new ServerRequest('GET', 'https://site.test/welcome');
        $sent = static fn (): int => count($app->container()->get(TransportFactory::class)->get('mail')->sent());

        $result = $app->render(self::asRole($app, $request, new RoleView('member', ['gold'])), $request);
        $this->assertSame('queued', (string) $result->response->getBody());
        $this->assertSame(1, $result->wouldSend(\Preflow\Core\Context\Bridge\NotifyChannel::NAME), 'the mail is captured');
        $this->assertSame(0, $sent(), 'and never sent');

        // Inside an admin's request that delivers, the role view's mail is still captured, never handed to the admin's frame.
        Contexts::run(self::admin($app, $request), function () use ($app, $request): void {
            $result = $app->render(self::asRole($app, $request, new RoleView('member', ['gold'])), $request);
            $this->assertSame(1, $result->wouldSend(\Preflow\Core\Context\Bridge\NotifyChannel::NAME));
            $this->assertSame([0, 0], Contexts::frame()->outbox()->pending(), 'the admin\'s frame holds nothing of it');
        });
        $this->assertSame(0, $sent());

        // A visitor's own request sends.
        $app->handle($request);
        $this->assertSame(1, $sent());
    }

    #[RunInSeparateProcess]
    public function test_the_page_cache_stores_nothing_for_a_role_render(): void
    {
        $app = $this->site(pageCache: true);
        $request = new ServerRequest('GET', 'https://site.test/');

        foreach ([Purpose::Preview, Purpose::Visit] as $purpose) {
            $ctx = RenderContext::fromRequest($request, $app->site(), new SystemClock())->asVisitor()->asRole(new RoleView('member', ['gold']))->because($purpose);
            $response = $app->render($ctx, $request)->response;
            $this->assertSame('MEMBERS-ONLY|in', self::main($response), $purpose->value);
            $this->assertSame('', $response->getHeaderLine('X-Page-Cache'), $purpose->value . ': the role render passes the cache by');
        }

        // Nothing was stored: the first visitor misses, renders the anonymous page and stores it.
        $first = $app->handle($request);
        $this->assertSame(['miss', 'PUBLIC|out'], [$first->getHeaderLine('X-Page-Cache'), self::main($first)]);
        $second = $app->handle($request);
        $this->assertSame(['hit', 'PUBLIC|out'], [$second->getHeaderLine('X-Page-Cache'), self::main($second)]);

        // A role render after the page was stored is never served the stored page.
        $response = $app->render(self::asRole($app, $request, new RoleView('member', ['gold'])), $request)->response;
        $this->assertSame(['', 'MEMBERS-ONLY|in'], [$response->getHeaderLine('X-Page-Cache'), self::main($response)]);
    }

    #[RunInSeparateProcess]
    public function test_a_role_render_never_leaks_into_the_next_request(): void
    {
        $app = $this->site();
        $request = new ServerRequest('GET', 'https://site.test/');
        $auth = $app->container()->get(AuthManager::class);

        $this->assertSame('MEMBERS-ONLY|in', self::main($app->render(self::asRole($app, $request, new RoleView('member', ['gold'])), $request)->response));
        $this->assertSame(0, Contexts::depth());
        $this->assertNull($auth->user(), 'outside every render nobody is signed in');

        $this->assertSame('PUBLIC|out', self::main($app->handle($request)), 'the next request is anonymous');

        // Inside an admin's request: the admin is back right after the role view.
        [$during, $after, $viewer] = Contexts::run(self::admin($app, $request), static function () use ($app, $request, $auth): array {
            $during = Contexts::run(self::asRole($app, $request, new RoleView('member', ['gold'])), static fn (): ?string => $auth->user()?->getAuthId());

            return [$during, $auth->user()?->getAuthId(), Contexts::current()->viewer];
        });
        $this->assertSame('preflow:synthetic:member:gold', $during);
        $this->assertSame('admin@site.test', $after);
        $this->assertFalse($viewer->isSynthetic());
        $this->assertSame('PUBLIC|out', self::main($app->handle($request)));
    }
}

/** Declares the permission the test page and routes check, as an app's provider does. */
final class ViewAsProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->get(PermissionRegistry::class)->declare('pages.edit', 'Edit pages', 'Pages');
    }
}

/** Answers the paths a test registers, else passes the request on. */
final class ViewAsRoutes implements MiddlewareInterface
{
    /** @var array<string, \Closure(ServerRequestInterface, Container): ResponseInterface> */
    public static array $routes = [];

    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = self::$routes[$request->getUri()->getPath()] ?? null;

        return $route !== null ? $route($request, $this->container) : $handler->handle($request);
    }
}
