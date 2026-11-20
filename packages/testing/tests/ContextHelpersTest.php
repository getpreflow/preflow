<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Testing\AuthTestHelpers;
use Preflow\Testing\FrameBalanceExtension;
use Preflow\Testing\WebApplication;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The testing helpers build the context of the request they prepare (actingAs(), at(),
 * withContext()), for that request only; FrameBalanceExtension checks that every test closes
 * the frames it opened.
 */
final class ContextHelpersTest extends TestCase
{
    use AuthTestHelpers;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/context_helpers_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>1,'engine'=>'twig','key'=>'context-helpers-key-0123456789ab','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'none', 'cookie' => false];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(ContextProbe::class, true) . ', ' . var_export(FailingProbe::class, true) . "];\n");
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'staff',
            'guards' => ['staff' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'population' => 'staff']],
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []]],
            'session' => ['cookie' => 'context_helpers', 'secure' => false],
        ], true) . ";\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_acting_as_builds_the_viewer_of_the_next_request(): void
    {
        $app = WebApplication::boot($this->dir);
        // What the frame says when it opens, before the kernel and its middleware (auth's among them).
        $opened = [];
        $app->onRequest(static function () use (&$opened): void {
            $opened[] = ContextProbe::describe(Contexts::current());
        });
        $user = new ArrayUser('ada@site.test', 'ada@site.test', '', []);

        $signedIn = (string) $app->handle($this->actingAs($user, new ServerRequest('GET', 'https://site.test/probe')))->getBody();
        $asOther = (string) $app->handle($this->actingAs($user, new ServerRequest('GET', 'https://site.test/probe'), 'members'))->getBody();
        $next = (string) $app->handle(new ServerRequest('GET', 'https://site.test/probe'))->getBody();

        $this->assertStringStartsWith('viewer=ada@site.test guard=staff', $opened[0], 'the frame handle() opens has the user as its viewer from the start, under the default guard');
        $this->assertStringStartsWith('viewer=ada@site.test guard=members', $opened[1], 'under the guard actingAs() names');
        $this->assertStringStartsWith('viewer=nobody', $opened[2], 'the next request is nobody\'s again');
        $this->assertStringStartsWith('viewer=ada@site.test guard=staff', $signedIn, 'and so is the request\'s middleware');
        $this->assertStringStartsWith('viewer=ada@site.test guard=members', $asOther);
        $this->assertStringStartsWith('viewer=nobody', $next);
    }

    #[RunInSeparateProcess]
    public function test_at_freezes_now_for_the_next_request(): void
    {
        $app = WebApplication::boot($this->dir);
        $then = new \DateTimeImmutable('2026-03-01T09:30:00+00:00');

        $frozen = (string) $app->handle(WebApplication::at($then, new ServerRequest('GET', 'https://site.test/probe')))->getBody();
        $drafts = (string) $app->handle(WebApplication::withContext(
            static fn (RenderContext $ctx): RenderContext => $ctx->workingCopies(),
            WebApplication::at($then, new ServerRequest('GET', 'https://site.test/probe')),
        ))->getBody();
        $next = (string) $app->handle(new ServerRequest('GET', 'https://site.test/probe'))->getBody();

        $this->assertStringContainsString('now=2026-03-01T09:30:00+00:00 frozen=yes reads=published', $frozen, '"now" of the whole request is the time at() gave');
        $this->assertStringContainsString('now=2026-03-01T09:30:00+00:00 frozen=yes reads=working', $drafts, 'withContext() derives on top of at()');
        $this->assertStringContainsString('frozen=no reads=published', $next, 'the next request runs on the app\'s clock again, as a plain visit');
        $this->assertStringNotContainsString('2026-03-01T09:30:00', $next);
    }

    #[RunInSeparateProcess]
    public function test_frame_depth_is_back_after_every_handle(): void
    {
        $app = WebApplication::boot($this->dir);

        $app->handle(new ServerRequest('GET', 'https://site.test/probe'));
        FrameBalanceExtension::assertBalanced(__METHOD__, Contexts::depth());
        try {
            $app->handle(new ServerRequest('GET', 'https://site.test/fail'));
        } catch (\RuntimeException) {
            // The error page or the exception: either way the frame closed.
        }
        FrameBalanceExtension::assertBalanced(__METHOD__, Contexts::depth());
        $this->assertSame(0, Contexts::depth());

        try {
            FrameBalanceExtension::assertBalanced('Site\\PageTest::test_open', 1);
            $this->fail('a frame left open is reported');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Site\\PageTest::test_open left 1 render frame open', $e->getMessage());
        }
    }

    public function test_the_framework_suites_check_the_frame_balance(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 3) . '/phpunit.xml');

        $this->assertStringContainsString('<bootstrap class="Preflow\\Testing\\FrameBalanceExtension"/>', $config);
    }
}

/** Answers /probe with what the frame it runs in says. */
final class ContextProbe implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/probe') {
            return $handler->handle($request);
        }

        return new Response(200, [], self::describe(Contexts::current()));
    }

    public static function describe(RenderContext $ctx): string
    {
        $primary = $ctx->viewer->primary();
        $guard = $primary === null ? null : array_search($primary, $ctx->viewer->identities, true);

        return sprintf(
            'viewer=%s guard=%s now=%s frozen=%s reads=%s',
            $primary?->getAuthId() ?? 'nobody',
            $guard === false || $guard === null ? '-' : (string) $guard,
            $ctx->now->format(DATE_ATOM),
            $ctx->isFrozen() ? 'yes' : 'no',
            $ctx->reads->readsWorkingCopies() ? 'working' : 'published',
        );
    }
}

/** /fail throws inside the frame. */
final class FailingProbe implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() === '/fail') {
            throw new \RuntimeException('the page failed');
        }

        return $handler->handle($request);
    }
}
