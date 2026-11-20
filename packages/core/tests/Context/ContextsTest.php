<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\NoRenderContext;
use Preflow\Core\Context\OutboxPolicy;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderFrame;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Context\OutboxChannel;
use Preflow\Core\Context\WritePolicy;
use Preflow\Core\Context\Writes;
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

    /**
     * A role viewer stays safe: inside its frame neither narrow() nor within() may swap it for
     * a real or anonymous viewer (asVisitor() is refused, not silently dropped); another role
     * view is fine. A new frame (run()) may render as someone else.
     */
    public function test_a_role_viewer_cannot_be_left_inside_its_frame(): void
    {
        $role = self::ctx('/a')->asRole(new RoleView('member', ['gold']));

        Contexts::run($role, function () use ($role): void {
            foreach ([
                'narrow asVisitor' => static fn () => Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->asVisitor()),
                'narrow as' => static fn () => Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->as(Viewer::anonymous())),
                'within' => static fn () => Contexts::within($role->asVisitor(), static fn (): null => null),
            ] as $name => $leave) {
                try {
                    $leave();
                    $this->fail("{$name}: leaving a role view inside its frame must be refused");
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('role view', $e->getMessage(), $name);
                }
                $this->assertSame($role, Contexts::current(), "{$name}: the role context is kept");
            }

            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->asRole(new RoleView('member', ['silver'])));
            $this->assertSame('role:member:silver', Contexts::current()->viewer->key(), 'another role view is allowed');

            $seen = Contexts::run(self::ctx('/b'), static fn (): string => Contexts::current()->viewer->key());
            $this->assertSame('anon', $seen, 'a new frame renders under its own context');
        });
    }

    /**
     * render()'s caller sets what a render may send and write: inside the frame, narrow() and
     * within() never loosen it (a Capture never becomes Deliver, a Skip never Allow), also in
     * the outermost frame, where a Deliver would send at once.
     */
    public function test_narrow_and_within_cannot_loosen_what_the_frame_was_opened_with(): void
    {
        $capturing = self::ctx('/a')->capturing()->skippingWrites();

        Contexts::run($capturing, function () use ($capturing): void {
            $this->assertSame(1, Contexts::depth());
            foreach ([
                'narrow delivering' => static fn () => Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->delivering()),
                'within delivering' => static fn () => Contexts::within($capturing->delivering(), static fn (): null => null),
                'narrow allow' => static fn () => Contexts::narrow(static fn (RenderContext $c): RenderContext => self::ctx('/a')->capturing()),
            ] as $name => $loosen) {
                try {
                    $loosen();
                    $this->fail("{$name}: loosening the frame must be refused");
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('opened', $e->getMessage(), $name);
                }
                $this->assertSame($capturing, Contexts::current(), $name);
            }

            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->discarding()->refusingWrites());
            $this->assertSame(OutboxPolicy::Discard, Contexts::current()->outbox, 'tightening is allowed');
            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->capturing()->skippingWrites());
            $this->assertSame(OutboxPolicy::Capture, Contexts::current()->outbox, 'back to what the frame was opened with');
        });

        // A frame opened delivering may narrow back to delivering.
        Contexts::run(self::ctx('/a'), static function (): void {
            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->capturing());
            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->delivering());
        });
        $this->assertSame(0, Contexts::depth());
    }

    /**
     * A role view never starts with within() (what the stretch queued would be sent with the
     * delivering frame); a role render in its own frame (run()) captures its mail, and nothing of
     * it reaches the delivering frame around it.
     */
    public function test_within_cannot_start_a_role_view_and_a_role_run_sends_nothing(): void
    {
        $outer = self::ctx('/a')->delivering();
        $channel = self::mailChannel();

        Contexts::run($outer, function () use ($outer, $channel): void {
            Contexts::frame()->outbox()->channel($channel);
            try {
                Contexts::within($outer->asRole(new RoleView('member', ['gold'])), static function (): void {
                    Contexts::frame()->outbox()->queue(new QueuedMail('gold@example.test'), 'mail');
                });
                $this->fail('within() must not start a role view');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('role view', $e->getMessage());
            }

            $held = Contexts::run($outer->asRole(new RoleView('member', ['gold'])), static function (): RenderFrame {
                Contexts::frame()->outbox()->queue(new QueuedMail('gold@example.test'), 'mail');

                return Contexts::frame();
            });
            $this->assertCount(1, $held->outbox()->drain(), 'the role render captured its mail');

            // Inside a role frame, a stretch of another role view is fine.
            Contexts::run($outer->asRole(new RoleView('member', ['gold'])), static function (): void {
                Contexts::within(Contexts::current()->asRole(new RoleView('member', ['silver'])), static fn (): null => null);
            });
        });

        $this->assertSame([], $channel->sent, 'nothing queued as a role was sent');
    }

    /**
     * A frame inside a frame never writes more than the frame around it does now: a fresh
     * context run inside a role view or a skipping frame skips, inside a refusing frame refuses.
     * The outbox is not clamped (the parent's policy decides what it gets).
     */
    public function test_a_nested_run_never_writes_more_than_its_parent(): void
    {
        $seen = static fn (): array => [Writes::policy(), Contexts::current()->outbox];

        $this->assertSame([WritePolicy::Skip, OutboxPolicy::Deliver], Contexts::run(self::ctx('/a')->asRole(new RoleView('member', ['gold'])), static fn (): array => Contexts::run(self::ctx('/b'), $seen)), 'inside a role view');
        $this->assertSame([WritePolicy::Skip, OutboxPolicy::Deliver], Contexts::run(self::ctx('/a')->skippingWrites(), static fn (): array => Contexts::run(self::ctx('/b'), $seen)), 'inside a skipping frame');
        $this->assertSame([WritePolicy::Refuse, OutboxPolicy::Deliver], Contexts::run(self::ctx('/a')->refusingWrites(), static fn (): array => Contexts::run(self::ctx('/b')->skippingWrites(), $seen)), 'inside a refusing frame');
        $this->assertSame([WritePolicy::Refuse, OutboxPolicy::Deliver], Contexts::run(self::ctx('/a')->skippingWrites(), static fn (): array => Contexts::run(self::ctx('/b')->refusingWrites(), $seen)), 'a stricter child keeps its own');
        $this->assertSame([WritePolicy::Allow, OutboxPolicy::Deliver], Contexts::run(self::ctx('/a'), static fn (): array => Contexts::run(self::ctx('/b'), $seen)), 'inside an allowing frame nothing changes');

        // The parent's current context counts: a frame narrowed to skipping clamps what it runs.
        $this->assertSame([WritePolicy::Skip, OutboxPolicy::Deliver], Contexts::run(self::ctx('/a'), static function () use ($seen): array {
            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->skippingWrites());

            return Contexts::run(self::ctx('/b'), $seen);
        }));
    }

    /** Refuse is stricter than Skip: a frame opened refusing writes never narrows to skipping them. */
    public function test_a_refusing_frame_cannot_narrow_to_skipping(): void
    {
        Contexts::run(self::ctx('/a')->refusingWrites(), function (): void {
            $this->expectException(\LogicException::class);
            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->skippingWrites());
        });
    }

    /** part() opens a frame like run(), whose partOf() names the caller's frame; it needs one. */
    public function test_part_opens_a_frame_that_names_the_frame_it_is_part_of(): void
    {
        $seen = Contexts::run(self::ctx('/'), static function (): array {
            $page = Contexts::frame();
            $own = Contexts::run(self::ctx('/own'), static fn (): ?RenderFrame => Contexts::frame()->partOf());
            [$part, $depth] = Contexts::part(self::ctx('/part'), static fn (): array => [Contexts::frame()->partOf(), Contexts::depth()]);

            return [$page, $own, $part, $depth, $page->partOf(), Contexts::frame()];
        });

        $this->assertNull($seen[1], 'a frame of its own (run()) is part of nothing');
        $this->assertSame($seen[0], $seen[2], 'a part frame names the caller\'s frame');
        $this->assertSame(2, $seen[3], 'it is a frame of its own');
        $this->assertNull($seen[4], 'the outermost frame is part of nothing');
        $this->assertSame($seen[0], $seen[5], 'the caller\'s frame is current again');

        $this->expectException(NoRenderContext::class);
        Contexts::part(self::ctx('/'), static fn (): null => null);
    }

    /**
     * An outermost frame narrowed to a role view (view as role) only captures: its owner's
     * deliverQueued() and deliverNow() send nothing and return quietly, logging nothing, and its
     * after-response work never runs.
     */
    public function test_delivery_on_an_outermost_frame_narrowed_to_a_role_view_is_skipped_quietly(): void
    {
        $channel = self::mailChannel();
        $ran = false;
        $log = (string) tempnam(sys_get_temp_dir(), 'ctx_log');
        $previous = ini_set('error_log', $log);
        try {
            $captured = Contexts::run(self::ctx('/'), static function () use ($channel, &$ran): array {
                Contexts::frame()->outbox()->channel($channel);
                Contexts::frame()->outbox()->afterResponse(static function () use (&$ran): void {
                    $ran = true;
                });
                Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->asRole(new RoleView('member', ['gold'])));
                Contexts::frame()->outbox()->queue(new QueuedMail('ada@example.test'), 'mail');
                Contexts::deliverQueued();
                Contexts::deliverNow();

                return Contexts::frame()->outbox()->drain();
            });
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame([], $channel->sent, 'nothing was sent');
        $this->assertCount(1, $captured, 'the mail stays captured');
        $this->assertFalse($ran, 'the after-response work never ran');
        $this->assertSame('', (string) file_get_contents($log), 'nothing was logged');
        @unlink($log);
    }

    private static function mailChannel(): OutboxChannel
    {
        return new class implements OutboxChannel {
            /** @var list<object> */
            public array $sent = [];

            public function name(): string
            {
                return 'mail';
            }

            public function deliver(array $items): void
            {
                array_push($this->sent, ...$items);
            }

            public function describe(object $item): string
            {
                return 'mail';
            }
        };
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
