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
use Preflow\Testing\ChecksFrameBalance;

/**
 * The contract's own tests: a path that renders under the context it is given passes all four;
 * a path that reads the surrounding frame, or the request bound in the container, fails; the
 * per-test pending list is a ratchet.
 */
final class RenderIsolationContractTest extends TestCase
{
    use ChecksFrameBalance;

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

    public function test_a_render_that_prints_the_outer_frames_nonce_fails_the_poison_test(): void
    {
        $failure = $this->failureOf(IsolationSelfTest::with(['audit' => IsolationSelfTest::printsNonce(outer: true)]), 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $failure, 'a render in a frame of its own printed the page\'s nonce');
        $this->assertNotInstanceOf(IncompleteTest::class, $failure);
        $this->assertStringContainsString('nonce of the frame around it', $failure->getMessage());
        $this->assertNull($this->failureOf(IsolationSelfTest::with(['audit' => IsolationSelfTest::printsNonce(outer: false)]), 'poison'), 'its own nonce passes');
        $this->assertNull($this->failureOf(IsolationSelfTest::with(['pdf' => IsolationSelfTest::printsNonce(outer: true)]), 'poison'), 'a PDF\'s HTML is part of the page and prints its nonce');
        $own = $this->failureOf(IsolationSelfTest::with(['pdf' => IsolationSelfTest::printsNonce(outer: false)]), 'poison');
        $this->assertInstanceOf(AssertionFailedError::class, $own, 'a PDF\'s HTML that prints a nonce of its own no longer joins the page');
        $this->assertStringContainsString('nonce of the page around it', $own->getMessage());
    }

    public function test_a_marker_in_a_captured_mail_body_fails_the_poison_test(): void
    {
        $failure = $this->failureOf(IsolationSelfTest::with(['preview' => IsolationSelfTest::mailsAmbientStateInTheBody()]), 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $failure, 'the body of a captured mail counts, not only its summary');
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

    public function test_each_path_gets_a_fresh_app_so_no_outcome_depends_on_the_paths_before_it(): void
    {
        // Both paths print a library only in the first render of their app (as a shared asset
        // registry does). On a shared app geo would pass after audit; on its own app it fails.
        $runner = IsolationSelfTest::with(
            ['audit' => IsolationSelfTest::printsOnce(), 'geo' => IsolationSelfTest::printsOnce()],
            ['audit' => ['frozen' => 'prints the library once per app']],
        );

        $failure = $this->failureOf($runner, 'frozen');

        $this->assertInstanceOf(AssertionFailedError::class, $failure);
        $this->assertNotInstanceOf(IncompleteTest::class, $failure);
        $this->assertStringContainsString('geo: two renders of the same context frozen with at() differ', $failure->getMessage());
    }

    public function test_restore_app_puts_the_app_s_own_state_back_also_after_a_failure(): void
    {
        $failure = $this->failureOf(IsolationSelfTest::with(['geo' => IsolationSelfTest::outer()]), 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $failure, 'the test failed');
        $this->assertSame('clean', IsolationSelfTest::$siteState, 'restoreApp() ran in tearDown()');
    }

    public function test_app_state_left_poisoned_fails_the_test(): void
    {
        $failure = $this->failureOf(IsolationSelfTest::with(['audit' => IsolationSelfTest::own()], restores: false), 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $failure);
        $this->assertStringContainsString('restoreApp()', $failure->getMessage());
        IsolationSelfTest::$siteState = 'clean';
    }

    public function test_a_path_that_changes_the_outer_cookies_outbox_or_bound_request_fails_the_frame_test(): void
    {
        foreach (['pullsOuterCookies', 'drainsOuterOutbox', 'rebindsRequest'] as $broken) {
            $failure = $this->failureOf(IsolationSelfTest::with(['verify' => IsolationSelfTest::$broken()]), 'frame');

            $this->assertInstanceOf(AssertionFailedError::class, $failure, $broken);
            $this->assertNotInstanceOf(IncompleteTest::class, $failure, $broken);
            $this->assertStringContainsString('verify: the outer frame changed', $failure->getMessage(), $broken);
        }
    }

    public function test_headers_or_captured_items_leaking_from_b_into_the_next_a_fail_the_interleave_test(): void
    {
        foreach (['header', 'captured'] as $leak) {
            $failure = $this->failureOf(IsolationSelfTest::with(['preview' => IsolationSelfTest::leaksAfterB($leak)]), 'interleave');

            $this->assertInstanceOf(AssertionFailedError::class, $failure, $leak);
            $this->assertNotInstanceOf(IncompleteTest::class, $failure, $leak);
            $this->assertStringContainsString('preview: a render in between', $failure->getMessage(), $leak);
        }
    }

    public function test_a_failing_path_whose_restore_fails_too_reports_both(): void
    {
        $failure = $this->failureOf(IsolationSelfTest::with(['geo' => IsolationSelfTest::outer()], restores: false), 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $failure);
        $this->assertStringContainsString('geo: the poisoned outer frame changed the render', $failure->getMessage(), 'the path\'s own failure is what is thrown');
        $this->assertStringContainsString('restoreApp() did not put the app\'s own ambient state back', $failure->getMessage(), 'the restore failure is appended');
        IsolationSelfTest::$siteState = 'clean';
    }

    public function test_an_ambient_state_that_poison_app_does_not_change_fails(): void
    {
        $failure = $this->failureOf(IsolationSelfTest::with(['audit' => IsolationSelfTest::own()], constantState: true), 'poison');

        $this->assertInstanceOf(AssertionFailedError::class, $failure);
        $this->assertNotInstanceOf(IncompleteTest::class, $failure);
        $this->assertStringContainsString('ambientState() is the same after poisonApp()', $failure->getMessage());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_a_path_that_closes_or_regenerates_the_outer_session_fails_the_frame_test(): void
    {
        foreach (['closesSession', 'regeneratesSession'] as $broken) {
            $failure = $this->failureOf(IsolationSelfTest::with(['verify' => IsolationSelfTest::$broken()], startsSession: true), 'frame');

            $this->assertInstanceOf(AssertionFailedError::class, $failure, $broken);
            $this->assertNotInstanceOf(IncompleteTest::class, $failure, $broken);
            $this->assertStringContainsString('verify: the outer frame changed', $failure->getMessage(), $broken);
        }
        // Proof that a session left alone passes.
        IsolationSelfTest::with(['verify' => IsolationSelfTest::own()], startsSession: true)->exercise(self::TESTS['frame']);
    }

    /**
     * A site runner needs neither #[IgnoreDeprecations] nor a Deprecations::reset() of its own: the
     * contract's own deprecated calls (the poison's setters) do not count under failOnDeprecation,
     * and a deprecated call of the site's still fails the run: in its page, also when an earlier
     * test of the contract reported it already, and in code only the poison reaches. Each fixture
     * runs in a PHPUnit subprocess under a site's deprecation settings
     * (Fixtures/Deprecations/phpunit.xml).
     */
    public function test_a_site_runner_needs_no_deprecation_handling_of_its_own(): void
    {
        [$exit, $output] = self::fixture('CleanRunnerCase');
        $this->assertSame(0, $exit, "a runner whose page calls nothing deprecated passes\n{$output}");
        $this->assertStringContainsString('OK (4 tests', $output);

        [$exit, $output] = self::fixture('ShimCallingRunnerCase');
        $this->assertNotSame(0, $exit, "a runner whose page calls a deprecated shim fails\n{$output}");
        $this->assertStringNotContainsString('FAILURES!', $output, 'it fails for the deprecation only, every test passes');
        $this->assertStringContainsString('InternalRender::mark() is deprecated', $output, 'the site\'s own deprecation is reported');
        $this->assertStringContainsString('ShimCallingRunnerCase::test_interleaved_renders_are_independent', $output, 'reported again by interleave after the reset in tearDown()');
        foreach (['Translator::setLocale()', 'Translator::useOverrides()', 'AuthManager::setUser()'] as $own) {
            $this->assertStringNotContainsString($own, $output, "the contract's own call of {$own} is not the site's");
        }

        // Only the contract's own setter calls are expected: site code the poison reaches is not.
        [$exit, $output] = self::fixture('PoisonOnlyShimRunnerCase');
        $this->assertNotSame(0, $exit, "a deprecated call reached only under the poison fails the run\n{$output}");
        $this->assertStringNotContainsString('FAILURES!', $output, 'it fails for the deprecation only, every test passes');
        $this->assertStringContainsString('InternalRender::mark() is deprecated', $output);
        $this->assertStringContainsString('PoisonOnlyShimRunnerCase::test_poisoned_ambient_state_never_reaches_the_render', $output, 'reported by the poison test itself');
        foreach (['Translator::setLocale()', 'Translator::useOverrides()', 'AuthManager::setUser()'] as $own) {
            $this->assertStringNotContainsString($own, $output, "the contract's own call of {$own} is not the site's");
        }
    }

    /** @return array{int, string} the exit code and the output of PHPUnit over the deprecation fixture $case */
    private static function fixture(string $case): array
    {
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/vendor/bin/phpunit')) {
            self::markTestSkipped("The deprecation fixtures run PHPUnit from the monorepo's root ({$root}/vendor/bin/phpunit), which is not there: run this test inside the Preflow monorepo.");
        }
        $dir = __DIR__ . '/Fixtures/Deprecations';
        $process = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/phpunit', '-c', $dir . '/phpunit.xml', '--no-progress', $dir . "/{$case}.php"],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
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

    private bool $restores = true;

    private bool $constantState = false;

    private bool $startsSession = false;

    /** Whether a B render (locale de) ran: leaksAfterB() then leaks into the next render. */
    private static bool $sawB = false;

    /**
     * @param array<string, \Closure> $paths
     * @param array<string, array<string, string>> $pending
     */
    public static function with(array $paths, array $pending = [], bool $restores = true, bool $constantState = false, bool $startsSession = false): self
    {
        $runner = new self('test_poisoned_ambient_state_never_reaches_the_render');
        $runner->selfPaths = $paths;
        $runner->selfPending = $pending;
        $runner->restores = $restores;
        $runner->constantState = $constantState;
        $runner->startsSession = $startsSession;

        return $runner;
    }

    /** Runs one of the contract's tests the way PHPUnit does: setUp, the test, tearDown. */
    public function exercise(string $test): void
    {
        self::$siteState = 'clean';
        self::$sawB = false;
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
        $nonces = new \Preflow\View\NonceGenerator();
        $app->container()->instance(\Preflow\View\NonceGenerator::class, $nonces);
        $app->container()->instance(\Preflow\View\AssetCollector::class, new \Preflow\View\AssetCollector($nonces));
        $app->container()->instance(\Preflow\View\Seo\SeoContext::class, new \Preflow\View\Seo\SeoContext());
        $app->container()->instance(\Preflow\Core\Http\CookieQueue::class, new \Preflow\Core\Http\CookieQueue());
        // What printsOnce() printed already, per app (as an asset registry's printed marks).
        $app->container()->instance('isolation.printed', new \ArrayObject());
        // As a booted app with native sessions starts one.
        if ($this->startsSession && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

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
        return ['siteState' => $this->constantState ? 'constant' : self::$siteState];
    }

    protected function restoreApp(Application $app): void
    {
        if ($this->restores) {
            self::$siteState = 'clean';
        }
    }

    /** Renders under its context, and prints a library only in the first render of its app. */
    public static function printsOnce(): \Closure
    {
        return static function (Application $app, RenderContext $ctx): RenderResult {
            $printed = $app->container()->get('isolation.printed');
            $library = isset($printed['lib']) ? '' : '<script>lib</script>';
            $printed['lib'] = true;

            return self::inFrame($ctx->because(Purpose::Audit)->discarding()->skippingWrites(), static fn (): string => $library . self::describe(Contexts::current()));
        };
    }

    /** Renders under its context, after closing the outer request's session. */
    public static function closesSession(): \Closure
    {
        return static function (Application $app, RenderContext $ctx): RenderResult {
            session_write_close();

            return self::own()($app, $ctx);
        };
    }

    /** Renders under its context, after giving the outer request's session a new id. */
    public static function regeneratesSession(): \Closure
    {
        return static function (Application $app, RenderContext $ctx): RenderResult {
            session_regenerate_id();

            return self::own()($app, $ctx);
        };
    }

    /** Renders under its context, after taking the outer request's queued cookies. */
    public static function pullsOuterCookies(): \Closure
    {
        return static function (Application $app, RenderContext $ctx): RenderResult {
            $app->container()->get(\Preflow\Core\Http\CookieQueue::class)->pull();

            return self::own()($app, $ctx);
        };
    }

    /** Renders under its context, after draining the outer frame's outbox. */
    public static function drainsOuterOutbox(): \Closure
    {
        return static function (Application $app, RenderContext $ctx): RenderResult {
            Contexts::frame()->outbox()->drain();

            return self::own()($app, $ctx);
        };
    }

    /** Renders under its context, and binds its own request in the container without putting the visitor's back. */
    public static function rebindsRequest(): \Closure
    {
        return static function (Application $app, RenderContext $ctx): RenderResult {
            $app->container()->instance(RequestContext::class, new RequestContext('/', 'GET', $ctx->request?->request));

            return self::own()($app, $ctx);
        };
    }

    /** Renders under its context; once a B render (locale de) ran, the next renders carry a header or a captured item. */
    public static function leaksAfterB(string $leak): \Closure
    {
        return static function (Application $app, RenderContext $ctx) use ($leak): RenderResult {
            $leaking = self::$sawB;
            if ($ctx->locale === 'de') {
                self::$sawB = true;
            }
            $result = self::inFrame($ctx->because(Purpose::Preview)->capturing()->skippingWrites(), static function () use ($leak, $leaking): string {
                if ($leaking && $leak === 'captured') {
                    Contexts::frame()->outbox()->queue(new \ArrayObject(['leaked' => true]), 'mail');
                }

                return self::describe(Contexts::current());
            });

            return $leaking && $leak === 'header'
                ? new RenderResult($result->response->withHeader('X-Leak', '1'), $result->captured, $result->skippedWrites)
                : $result;
        };
    }

    /**
     * Renders under its context, capturing a mail (notify's queued item: it holds a closure, so it
     * cannot be serialized) whose body carries the app's ambient state; its summary does not.
     */
    public static function mailsAmbientStateInTheBody(): \Closure
    {
        return static fn (Application $app, RenderContext $ctx): RenderResult => self::inFrame(
            $ctx->because(Purpose::Preview)->capturing()->skippingWrites(),
            static function (): string {
                Contexts::frame()->outbox()->queue(new \Preflow\Notify\Deferred\Deferred(
                    new \Preflow\Notify\Email('member@site.test', 'Welcome', 'Hello ' . self::$siteState),
                    'email',
                    static function (): void {},
                    'Email to member@site.test: Welcome',
                ), 'notify');

                return self::describe(Contexts::current());
            },
        );
    }

    /** Renders under its context and prints a CSP nonce: the outer frame's (taken before its frame opens), or its own. */
    public static function printsNonce(bool $outer): \Closure
    {
        return static function (Application $app, RenderContext $ctx) use ($outer): RenderResult {
            $nonces = $app->container()->get(\Preflow\View\NonceGenerator::class);
            $page = $outer ? $nonces->get() : null;

            return self::inFrame(
                $ctx->because(Purpose::Audit)->discarding()->skippingWrites(),
                static fn (): string => self::describe(Contexts::current()) . '|<script nonce="' . ($page ?? $nonces->get()) . '"></script>',
            );
        };
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
        // Since 0.81 the collectors keep their state per frame, so a path inherits the outer
        // frame's only by reading them before its own frame opens.
        return static function (Application $app, RenderContext $ctx): RenderResult {
            $container = $app->container();
            $title = $container->bound(\Preflow\View\Seo\SeoContext::class) ? ($container->get(\Preflow\View\Seo\SeoContext::class)->get()?->title ?? '') : '';
            $css = $container->bound(\Preflow\View\AssetCollector::class) ? $container->get(\Preflow\View\AssetCollector::class)->renderCss() : '';

            return self::inFrame(
                $ctx->because(Purpose::Preview)->capturing()->skippingWrites(),
                static fn (): string => self::describe(Contexts::current()) . "|{$title}|{$css}",
            );
        };
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
