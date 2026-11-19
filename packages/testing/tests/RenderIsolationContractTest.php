<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\Site;
use Preflow\Core\Http\RequestContext;
use Preflow\Testing\ContextFactory;
use Preflow\Testing\Contract\RenderIsolationContract;

/**
 * The contract's own tests: a path that renders under the context it is given passes all four;
 * a path that reads the surrounding frame, or the request bound in the container, fails; the
 * per-test pending list is a ratchet.
 */
final class RenderIsolationContractTest extends TestCase
{
    private const TESTS = [
        'poison' => 'test_poisoned_ambient_state_never_reaches_the_render',
        'frame' => 'test_outer_frame_is_byte_equal_after_each_render',
        'interleave' => 'test_interleaved_renders_are_independent',
        'frozen' => 'test_frozen_clock_renders_identically',
    ];

    public function test_a_path_that_renders_under_its_own_context_passes_every_test(): void
    {
        foreach (self::TESTS as $test) {
            IsolationSelfTest::with(['audit' => IsolationSelfTest::own()])->exercise($test);
        }
        $this->addToAssertionCount(1);
    }

    public function test_a_path_that_reads_the_outer_frame_fails_poison_interleave_and_frozen(): void
    {
        foreach (['poison', 'interleave', 'frozen'] as $test) {
            $failure = $this->failureOf(IsolationSelfTest::with(['geo' => IsolationSelfTest::outer()]), $test);
            $this->assertInstanceOf(AssertionFailedError::class, $failure, "{$test} catches a path that reads the outer frame");
            $this->assertNotInstanceOf(IncompleteTest::class, $failure, "{$test}: a path that is not pending fails");
            $this->assertStringContainsString('geo', $failure->getMessage());
        }
    }

    public function test_a_path_that_reads_the_bound_request_fails_the_poison_test(): void
    {
        $failure = $this->failureOf(IsolationSelfTest::with(['rebuild' => IsolationSelfTest::boundRequest()]), 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $failure);
        $this->assertNotInstanceOf(IncompleteTest::class, $failure);
        $this->assertStringContainsString('zz-ambient-marker', $failure->getMessage() . ($failure instanceof \PHPUnit\Framework\ExpectationFailedException ? $failure->getComparisonFailure()?->getDiff() : ''));
    }

    public function test_a_path_that_inherits_the_outer_assets_or_page_meta_fails_the_poison_test(): void
    {
        $failure = $this->failureOf(IsolationSelfTest::with(['preview' => IsolationSelfTest::inheritsCollectors()]), 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $failure);
        $this->assertNotInstanceOf(IncompleteTest::class, $failure);
    }

    public function test_a_pending_test_that_fails_is_incomplete_with_its_whole_diff(): void
    {
        $runner = IsolationSelfTest::with(['geo' => IsolationSelfTest::outer()], ['geo' => ['poison' => 'reads the outer frame']]);

        $failure = $this->failureOf($runner, 'poison');

        $this->assertInstanceOf(IncompleteTest::class, $failure);
        $this->assertStringContainsString('geo/poison (reads the outer frame)', $failure->getMessage());
        $this->assertStringContainsString('--- Expected', $failure->getMessage(), 'the diff of the comparison is kept');
        $this->assertStringContainsString('+++ Actual', $failure->getMessage());
    }

    public function test_a_pending_test_that_passes_fails_the_ratchet(): void
    {
        $runner = IsolationSelfTest::with(
            ['audit' => IsolationSelfTest::own(), 'geo' => IsolationSelfTest::outer()],
            ['audit' => ['*' => 'not migrated'], 'geo' => ['poison' => 'reads the outer frame']],
        );

        $failure = $this->failureOf($runner, 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $failure);
        $this->assertNotInstanceOf(IncompleteTest::class, $failure);
        $this->assertStringContainsString('audit/poison passes: remove it from pending()', $failure->getMessage());
        $this->assertStringContainsString('geo/poison (reads the outer frame)', $failure->getMessage(), 'the other outcomes are listed too');
    }

    public function test_pending_names_known_paths_and_tests_only(): void
    {
        $unknownTest = $this->failureOf(IsolationSelfTest::with(['audit' => IsolationSelfTest::own()], ['audit' => ['poisn' => 'typo']]), 'poison');
        $unknownPath = $this->failureOf(IsolationSelfTest::with(['audit' => IsolationSelfTest::own()], ['geo' => ['*' => 'not covered']]), 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $unknownTest);
        $this->assertNotInstanceOf(IncompleteTest::class, $unknownTest);
        $this->assertInstanceOf(AssertionFailedError::class, $unknownPath);
        $this->assertNotInstanceOf(IncompleteTest::class, $unknownPath);
    }

    public function test_an_app_s_own_ambient_source_is_poisoned_and_checked(): void
    {
        $runner = IsolationSelfTest::with(['audit' => IsolationSelfTest::resetsAppState()]);

        $failure = $this->failureOf($runner, 'frame');

        $this->assertInstanceOf(AssertionFailedError::class, $failure, 'a render that resets the app\'s own ambient state changes the outer frame');
        $this->assertStringContainsString('audit', $failure->getMessage());
    }

    private function failureOf(IsolationSelfTest $runner, string $test): ?\Throwable
    {
        try {
            $runner->exercise(self::TESTS[$test]);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}

/**
 * The contract over a bare application (not booted: the paths below need only its container).
 * Each path prints what it renders under: locale, viewer, the request's cookie m, the time, and
 * the page title and CSS it would print.
 */
final class IsolationSelfTest extends RenderIsolationContract
{
    /** The app's own ambient source, which poisonApp() sets and ambientState() reads. */
    public static string $siteState = 'clean';

    /** @var array<string, \Closure> */
    private array $selfPaths = [];

    /** @var array<string, array<string, string>> */
    private array $selfPending = [];

    /**
     * @param array<string, \Closure> $paths
     * @param array<string, array<string, string>> $pending
     */
    public static function with(array $paths, array $pending = []): self
    {
        $runner = new self('test_poisoned_ambient_state_never_reaches_the_render');
        $runner->selfPaths = $paths;
        $runner->selfPending = $pending;

        return $runner;
    }

    /** Runs one of the contract's tests the way PHPUnit does: setUp, the test, tearDown. */
    public function exercise(string $test): void
    {
        self::$siteState = 'clean';
        $this->setUp();
        try {
            $this->{$test}();
        } finally {
            $this->tearDown();
        }
    }

    protected function app(): Application
    {
        $app = Application::create(['app' => ['name' => 'self-test', 'key' => 'isolation-self-test-key-0123456789']]);
        // The collectors a booted app binds: the contract plants its marker asset and title in them.
        $app->container()->instance(\Preflow\View\AssetCollector::class, new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator()));
        $app->container()->instance(\Preflow\View\Seo\SeoContext::class, new \Preflow\View\Seo\SeoContext());

        return $app;
    }

    protected function context(): RenderContext
    {
        return ContextFactory::forTest(new Site('https://site.test', ['en', 'de'], 'en'));
    }

    protected function paths(): array
    {
        return $this->selfPaths;
    }

    protected function pending(): array
    {
        return $this->selfPending;
    }

    protected function clockFormat(): ?string
    {
        return DATE_ATOM;
    }

    protected function poisonApp(Application $app): void
    {
        self::$siteState = $this->marker();
    }

    protected function ambientState(Application $app): array
    {
        return ['siteState' => self::$siteState];
    }

    /** Renders in a frame of its own under the context it is given. */
    public static function own(): \Closure
    {
        return static fn (Application $app, RenderContext $ctx): RenderResult
            => self::inFrame($ctx->because(Purpose::Audit)->discarding()->skippingWrites(), static fn (): string => self::describe(Contexts::current()));
    }

    /** Ignores its context and reads the frame around it. */
    public static function outer(): \Closure
    {
        return static fn (Application $app, RenderContext $ctx): RenderResult
            => new RenderResult(new Response(200, [], self::describe(Contexts::current())));
    }

    /** Renders under its context but takes the cookie from the request bound in the container. */
    public static function boundRequest(): \Closure
    {
        return static fn (Application $app, RenderContext $ctx): RenderResult => self::inFrame(
            $ctx->because(Purpose::Rebuild)->discarding()->skippingWrites(),
            static function () use ($app): string {
                $container = $app->container();
                $bound = $container->bound(RequestContext::class) ? $container->get(RequestContext::class)->request : null;

                return self::describe(Contexts::current()) . '|bound cookie ' . ($bound?->getCookieParams()['m'] ?? '');
            },
        );
    }

    /** Renders under its context but prints the page title and CSS collected around it. */
    public static function inheritsCollectors(): \Closure
    {
        return static fn (Application $app, RenderContext $ctx): RenderResult => self::inFrame(
            $ctx->because(Purpose::Preview)->capturing()->skippingWrites(),
            static function () use ($app): string {
                $container = $app->container();
                $title = $container->bound(\Preflow\View\Seo\SeoContext::class) ? ($container->get(\Preflow\View\Seo\SeoContext::class)->get()?->title ?? '') : '';
                $css = $container->bound(\Preflow\View\AssetCollector::class) ? $container->get(\Preflow\View\AssetCollector::class)->renderCss() : '';

                return self::describe(Contexts::current()) . "|{$title}|{$css}";
            },
        );
    }

    /** Renders under its context but resets the app's own ambient state. */
    public static function resetsAppState(): \Closure
    {
        return static function (Application $app, RenderContext $ctx): RenderResult {
            self::$siteState = 'clean';

            return self::inFrame($ctx->because(Purpose::Audit)->discarding()->skippingWrites(), static fn (): string => self::describe(Contexts::current()));
        };
    }

    private static function describe(RenderContext $ctx): string
    {
        return $ctx->locale . '|' . $ctx->viewer->key() . '|' . $ctx->reads->key() . '|'
            . ($ctx->request?->request?->getCookieParams()['m'] ?? '') . '|' . $ctx->now->format(DATE_ATOM);
    }

    /** @param \Closure(): string $render */
    private static function inFrame(RenderContext $ctx, \Closure $render): RenderResult
    {
        $frame = null;
        $body = Contexts::run($ctx, static function () use (&$frame, $render): string {
            $frame = Contexts::frame();

            return $render();
        });

        return new RenderResult(new Response(200, [], $body), $frame->outbox()->drain(), $frame->skippedWrites());
    }
}
