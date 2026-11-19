<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

require_once __DIR__ . '/Fixtures/InMemoryRedirectStore.php';
require_once __DIR__ . '/Fixtures/InMemoryNotFoundLog.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SkippedWrite;
use Preflow\Core\Context\Site;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\NotFoundFilter;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectMiddleware;
use Preflow\Routing\Redirect\RedirectStore;
use Preflow\Routing\Tests\Redirect\Fixtures\InMemoryNotFoundLog;
use Preflow\Routing\Tests\Redirect\Fixtures\InMemoryRedirectStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The redirect hit counters and the not-found log are 'log' writes of the write policy. */
final class RedirectWritePolicyTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        if ($this->dir === '') {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_redirect_hits_and_not_found_log_follow_the_write_policy(): void
    {
        $old = new Redirect(Redirect::id('exact', 'en', '/old'), '/old', 'en', false, AreaMode::One, null, null, '/new', 301);
        $store = new InMemoryRedirectStore([$old]);
        $log = new InMemoryNotFoundLog();
        $middleware = new RedirectMiddleware(
            $store,
            null,
            $log,
            new NonPublicPaths(),
            new NotFoundFilter(),
            'en',
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-04 12:00:00'),
        );
        $site = new Site('https://example.com', ['en'], 'en');
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-04 12:00:00'));

        foreach ([
            'the audit (Skip)' => RenderContext::internal(Purpose::Audit, $site, $clock, '/old'),
            'a preview fragment (Refuse: log writes are skipped)' => RenderContext::fromRequest($this->get('/old'), $site, $clock)->refusingWrites(),
        ] as $case => $ctx) {
            $frame = null;
            [$redirect, $missing] = Contexts::run($ctx, function () use ($middleware, &$frame): array {
                $frame = Contexts::frame();

                return [$this->answer($middleware, '/old'), $this->answer($middleware, '/missing')];
            });

            $this->assertSame(301, $redirect?->getStatusCode(), "{$case}: still redirected");
            $this->assertSame('/new', $redirect->getHeaderLine('Location'));
            $this->assertNull($missing, "{$case}: still a 404");
            $this->assertSame([], $store->hits, "{$case}: no hit counted");
            $this->assertSame([], $log->recorded, "{$case}: no not-found line");
            $this->assertEquals([
                new SkippedWrite('redirects', 'redirect-hits', $old->id, 'log'),
                new SkippedWrite('redirects', 'not-found', '/missing', 'log'),
            ], $frame->skippedWrites(), "{$case}: both reported");
        }

        // A visitor's request counts and records.
        Contexts::run(RenderContext::fromRequest($this->get('/old'), $site, $clock), function () use ($middleware): void {
            $this->answer($middleware, '/old');
            $this->answer($middleware, '/missing');
        });
        $this->assertSame([$old->id], $store->hits);
        $this->assertSame([['/missing', 'en']], $log->recorded);
    }

    public function test_clean_path_one_hop_hit_follows_the_write_policy(): void
    {
        $this->dir = sys_get_temp_dir() . '/redirect_write_policy_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/routing.php', "<?php\nreturn ['canonical_paths' => true];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>home</body></html>');

        $app = Application::create($this->dir);
        $app->boot();
        $store = new class implements RedirectStore {
            /** @var list<array{string, string}> */
            public array $hits = [];

            public function exact(string $path, string $locale): ?Redirect
            {
                return $path === '/old-help'
                    ? new Redirect('a', '/old-help', 'en', false, AreaMode::One, null, null, '/help', 301)
                    : null;
            }

            public function areas(string $locale): array
            {
                return [];
            }

            public function hit(Redirect $redirect, \DateTimeImmutable $at): void
            {
                $this->hits[] = [$redirect->id, $at->format(DATE_ATOM)];
            }
        };
        $app->container()->instance(RedirectStore::class, $store);
        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/old-help/');
        $clock = new FrozenClock(new \DateTimeImmutable('2026-03-01T10:00:00+00:00'));

        $result = $app->render(RenderContext::fromRequest($request, $app->site(), $clock)->skippingWrites(), $request);

        $this->assertSame(301, $result->response->getStatusCode(), 'the entry still answers in one hop');
        $this->assertSame('/help', $result->response->getHeaderLine('Location'));
        $this->assertSame([], $store->hits, 'no hit counted');
        $this->assertEquals([new SkippedWrite('redirects', 'redirect-hits', 'a', 'log')], $result->skippedWrites);

        // A visit counts the hit, at the context's "now".
        $app->render(RenderContext::fromRequest($request, $app->site(), $clock), $request);
        $this->assertSame([['a', '2026-03-01T10:00:00+00:00']], $store->hits);
    }

    /** The middleware's answer for GET $path: the redirect, or null for the rethrown 404. */
    private function answer(RedirectMiddleware $middleware, string $path): ?ResponseInterface
    {
        try {
            return $middleware->process($this->get($path), new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new NotFoundHttpException();
                }
            });
        } catch (NotFoundHttpException) {
            return null;
        }
    }

    private function get(string $path): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'https://example.com' . $path);
    }
}
