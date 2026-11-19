<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\NoRenderContext;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderFrame;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\LocaleUrls;

final class ContextsTest extends TestCase
{
    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    public function test_current_outside_a_frame_throws_and_peek_is_null(): void
    {
        $this->assertNull(Contexts::peek());
        $this->assertSame(0, Contexts::depth());

        $line = __LINE__ + 2;
        try {
            Contexts::current();
            $this->fail('current() outside a frame must throw');
        } catch (NoRenderContext $e) {
            $this->assertStringContainsString(__FILE__ . ':' . $line, $e->getMessage(), 'the message names the caller');
        }

        $this->expectException(NoRenderContext::class);
        Contexts::frame();
    }

    public function test_run_nests_and_returns_to_the_outer_context(): void
    {
        $a = self::ctx('/a');
        $b = self::ctx('/b');

        $result = Contexts::run($a, function () use ($a, $b): string {
            $this->assertSame($a, Contexts::current());
            $this->assertSame(1, Contexts::depth());
            $outer = Contexts::frame();

            $inner = Contexts::run($b, function () use ($b, $outer): string {
                $this->assertSame($b, Contexts::current());
                $this->assertSame($b, Contexts::peek());
                $this->assertSame(2, Contexts::depth());
                $this->assertNotSame($outer, Contexts::frame(), 'run() opens a new frame');

                return 'inner';
            });

            $this->assertSame('inner', $inner);
            $this->assertSame($a, Contexts::current());
            $this->assertSame($outer, Contexts::frame());
            $this->assertSame(1, Contexts::depth());

            return 'outer';
        });

        $this->assertSame('outer', $result, 'run() returns what the render returns');
        $this->assertSame(0, Contexts::depth());
        $this->assertNull(Contexts::peek());
    }

    /** Review Focus 1: an audit page that throws inside an admin request. */
    public function test_run_restores_the_outer_frame_when_the_render_throws(): void
    {
        $admin = self::ctx('/admin');
        $audit = self::ctx('/page');

        Contexts::run($admin, function () use ($admin, $audit): void {
            $outer = Contexts::frame();
            Contexts::frame()->outbox()->queue(new \stdClass(), 'cookies');

            try {
                Contexts::run($audit, static function (): void {
                    Contexts::frame()->outbox()->queue(new QueuedMail('to@example.test'), 'mail');
                    Contexts::frame()->outbox()->queue(new \stdClass(), 'cookies');
                    Contexts::frame()->outbox()->afterResponse(static fn () => throw new \LogicException('must never run'));
                    throw new \RuntimeException('render failed');
                });
                $this->fail('the inner render must rethrow');
            } catch (\RuntimeException $e) {
                $this->assertSame('render failed', $e->getMessage());
            }

            $this->assertSame($admin, Contexts::current());
            $this->assertSame($outer, Contexts::frame());
            $this->assertSame(1, Contexts::depth());

            $items = Contexts::frame()->outbox()->drain();
            $this->assertCount(1, $items, "the inner frame's mail and cookies never reach the outer one");
            $this->assertSame('cookies', $items[0]->channel);
            Contexts::frame()->outbox()->runAfterResponse();

            // A caller holding a capturing frame (the RenderResult pattern) gets nothing from a render that threw.
            $held = null;
            try {
                Contexts::run($audit->capturing(), static function () use (&$held): void {
                    $held = Contexts::frame();
                    Contexts::frame()->outbox()->queue(new QueuedMail('to@example.test'), 'mail');
                    throw new \RuntimeException('render failed');
                });
            } catch (\RuntimeException) {
            }
            $this->assertInstanceOf(RenderFrame::class, $held);
            $this->assertSame([], $held->outbox()->drain(), "a failed render's mail is never reported as captured");
        });

        $this->assertSame(0, Contexts::depth());
    }

    public function test_narrow_changes_the_current_frame_only(): void
    {
        $a = self::ctx('/a');

        Contexts::run($a, function () use ($a): void {
            Contexts::run(self::ctx('/b'), function (): void {
                Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->in('de'));
                $this->assertSame('de', Contexts::current()->locale);
                $this->assertSame('/b', Contexts::current()->request?->path, 'the rest of the context is kept');
            });

            $this->assertSame($a, Contexts::current());
            $this->assertSame('en', Contexts::current()->locale);

            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->in('de'));
            $this->assertSame('de', Contexts::current()->locale, 'narrow() holds for the rest of the frame');
            $this->assertSame('en', $a->locale, 'the context itself stays as it was');
        });
    }

    public function test_narrow_cannot_outlive_its_frame(): void
    {
        $a = self::ctx('/a');

        Contexts::run($a, function () use ($a): void {
            Contexts::run(self::ctx('/b'), static function (): void {
                Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->in('de'));
            });
            $this->assertSame($a, Contexts::current());
        });
        $this->assertNull(Contexts::peek());

        // A narrow inside within() lasts only for that stretch.
        Contexts::run($a, function () use ($a): void {
            Contexts::within($a->in('de'), static function (): void {
                Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->in('fr'));
            });
            $this->assertSame($a, Contexts::current());
        });

        // Outside every frame there is nothing to narrow.
        $this->expectException(NoRenderContext::class);
        Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->in('de'));
    }

    /** Review Focus 1: a stretch that throws gives the context back and keeps what it queued. */
    public function test_within_restores_the_context_when_fn_throws_and_keeps_what_it_queued(): void
    {
        $a = self::ctx('/a');

        Contexts::run($a, function () use ($a): void {
            $frame = Contexts::frame();

            try {
                Contexts::within($a->in('de'), function () use ($frame): void {
                    $this->assertSame('de', Contexts::current()->locale);
                    $this->assertSame(1, Contexts::depth(), 'within() opens no frame');
                    $this->assertSame($frame, Contexts::frame());
                    Contexts::frame()->outbox()->queue(new QueuedMail('to@example.test'), 'mail');
                    throw new \RuntimeException('stretch failed');
                });
                $this->fail('within() must rethrow');
            } catch (\RuntimeException $e) {
                $this->assertSame('stretch failed', $e->getMessage());
            }

            $this->assertSame($a, Contexts::current(), 'the previous context is back');
            $this->assertSame(1, Contexts::depth());
            $items = $frame->outbox()->drain();
            $this->assertCount(1, $items, 'what the stretch queued stays in the frame');
            $this->assertInstanceOf(QueuedMail::class, $items[0]->item);
        });

        $this->assertSame(0, Contexts::depth());
        $this->expectException(NoRenderContext::class);
        Contexts::within($a, static fn () => null);
    }

    public function test_within_uses_the_same_frame(): void
    {
        $a = self::ctx('/a');

        Contexts::run($a, function () use ($a): void {
            $outside = Contexts::frame()->slot(\ArrayObject::class, static fn (): \ArrayObject => new \ArrayObject());
            $outside[] = 'outside';

            $returned = Contexts::within($a->in('de'), function () use ($outside): string {
                $inside = Contexts::frame()->slot(\ArrayObject::class, static fn (): \ArrayObject => new \ArrayObject());
                $this->assertSame($outside, $inside, 'one slot object for the whole frame');
                $inside[] = 'inside';

                return 'done';
            });

            $this->assertSame('done', $returned, 'within() returns what fn returns');
            $this->assertSame($a, Contexts::current());
            $this->assertSame(['outside', 'inside'], $outside->getArrayCopy());

            Contexts::run($a, function () use ($outside): void {
                $this->assertNotSame($outside, Contexts::frame()->slot(\ArrayObject::class, static fn (): \ArrayObject => new \ArrayObject()), 'a new frame has its own slots');
            });
        });
    }

    public function test_on_enter_hook_runs_and_its_leave_closure_runs_in_finally(): void
    {
        $log = [];
        Contexts::onEnter(function (RenderContext $ctx, RenderFrame $frame) use (&$log): ?\Closure {
            $this->assertSame($ctx, Contexts::current(), 'the hook runs inside the new frame');
            $this->assertSame($frame, Contexts::frame());
            $log[] = 'enter ' . $ctx->request?->path;
            if ($ctx->request?->path === '/skip') {
                return null;
            }

            return function () use (&$log, $ctx): void {
                $this->assertSame($ctx, Contexts::peek(), 'the leave closure runs before the frame is gone');
                $log[] = 'leave ' . $ctx->request?->path;
            };
        });

        Contexts::run(self::ctx('/a'), static function (): void {
            Contexts::run(self::ctx('/skip'), static fn () => null);
            try {
                Contexts::run(self::ctx('/b'), static fn () => throw new \RuntimeException('render failed'));
            } catch (\RuntimeException) {
            }
            Contexts::within(self::ctx('/c'), static fn () => null);
        });

        $this->assertSame(['enter /a', 'enter /skip', 'enter /b', 'leave /b', 'leave /a'], $log, 'leave runs also when the render throws; within() opens no frame');
        $this->assertSame(0, Contexts::depth());
    }

    public function test_run_detects_an_unbalanced_frame_stack_in_debug_mode(): void
    {
        $assertions = ini_get('zend.assertions');
        ini_set('zend.assertions', '0'); // the debug flag alone must report it, as on a server
        $errorLog = ini_get('error_log');
        $log = tempnam(sys_get_temp_dir(), 'contexts_log_');
        ini_set('error_log', $log);
        try {
            Contexts::debug(false);
            Contexts::run(self::ctx('/a'), static function (): void {
                Contexts::reset();
            });
            $this->assertSame(0, Contexts::depth(), 'without debug mode the stack is repaired for the visitor');
            $this->assertStringContainsString('The render frame stack is out of balance', (string) file_get_contents($log), 'and the imbalance is logged');

            Contexts::debug(true);
            try {
                Contexts::run(self::ctx('/a'), static function (): void {
                    Contexts::reset(); // also turns debug mode off; the frame keeps the flag it opened with
                });
                $this->fail('an unbalanced stack must be reported in debug mode');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('depth', $e->getMessage());
            }
        } finally {
            ini_set('zend.assertions', (string) $assertions);
            ini_set('error_log', (string) $errorLog);
            @unlink($log);
            Contexts::debug(false);
        }
    }

    private static function ctx(string $path): RenderContext
    {
        $site = new Site('https://example.test', ['en', 'de', 'fr'], 'en', new LocaleUrls(['en', 'de', 'fr'], 'en'));

        return RenderContext::fromRequest(new ServerRequest('GET', $path), $site, new SystemClock());
    }
}

final readonly class QueuedMail
{
    public function __construct(public string $to) {}
}
