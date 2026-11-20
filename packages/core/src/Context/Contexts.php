<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * The one holder of the current render context. Three tools change it, and only these:
 * - run(): a new render in a new frame (frames nest; the outer one is back afterwards);
 * - narrow(): the rest of the current frame (middleware that resolved the user or the locale);
 * - within(): a stretch of the current frame (a template preview in the visitor's texts), with
 *   the previous context back afterwards, also when the stretch throws.
 * Both note on the frame when they enter a context that is not the public default
 * (RenderFrame::departures()), so the page cache never stores a render that read as one.
 * Neither loosens the frame: what run()'s caller allowed it to send and write stays the most it
 * may (a frame opened capturing or discarding never delivers, one opened skipping or refusing
 * writes never allows them), and a role viewer (view as role) is never swapped for a real or an
 * anonymous one inside its frame (another role view is fine). Both are refused with a
 * LogicException; a render as someone else opens its own frame with run().
 *
 * Code without a context parameter reads current(); outside every render it throws
 * NoRenderContext. Only code with a documented default for "no render" (DataManager writes,
 * the translator, LiveContent, the notify bridge, shutdown hooks) may use peek().
 *
 * An internal render returns its RenderResult from the frame it opened:
 *
 *     $frame = null;
 *     $response = Contexts::run($ctx, function () use (&$frame, $request) {
 *         $frame = Contexts::frame();
 *         return $handler->handle($request);
 *     });
 *     return new RenderResult($response, $frame->outbox()->drain(), $frame->skippedWrites());
 *
 * @since 0.81.0
 */
final class Contexts
{
    /** @var list<RenderFrame> the open frames, the current one last */
    private static array $frames = [];

    private static ?RenderFrame $top = null;

    private static bool $debug = false;

    /** Rounds of delivery and after-response work the outermost frame runs at most. */
    private const DELIVERY_ROUNDS = 10;

    /** @var array<int|string, \Closure(RenderContext, RenderFrame): (\Closure(): void)|null> in the order added; named ones by their name */
    private static array $enterHooks = [];

    /**
     * Runs $render in a new frame under $ctx and returns what it returns. The frame always
     * closes, also when $render throws: on success its outbox follows $ctx's OutboxPolicy as narrowed by then
     * (see FrameOutbox); when $render throws, its outbox is dropped. Its skipped writes always
     * move to the parent frame, so the run that opened a frame reports its nested renders' too.
     *
     * The onEnter() hooks run after the frame opened, their leave closures (in reverse order)
     * after the outbox policy and before the frame is gone. In debug mode (debug(), as it was
     * when the frame opened), a frame stack that is not as run() left it (a frame left open, a reset inside a render) throws a
     * LogicException; with assertions on, assert() reports it too.
     *
     * @template R
     * @param \Closure(): R $render
     * @return R
     */
    public static function run(RenderContext $ctx, \Closure $render): mixed
    {
        $depth = count(self::$frames);
        $debug = self::$debug;
        $parent = self::$top;
        $frame = new RenderFrame($ctx, $parent);
        self::$frames[] = $frame;
        self::$top = $frame;

        $leave = [];
        $failure = null;
        $result = null;
        try {
            foreach (self::$enterHooks as $hook) {
                $closure = $hook($ctx, $frame);
                if ($closure !== null) {
                    $leave[] = $closure;
                }
            }
            $result = $render();
            if (self::$top === $frame) {
                self::close($frame, $parent);
            } else {
                // The stack was torn under the render (reported below): nothing it queued is sent.
                $frame->outbox()->discard();
            }
        } catch (\Throwable $e) {
            // A render that throws leaves nothing behind: no mail, cookie or after-response work.
            $failure = $e;
            $frame->outbox()->discard();
        }

        foreach (array_reverse($leave) as $closure) {
            try {
                $closure();
            } catch (\Throwable $e) {
                if ($failure === null) {
                    $failure = $e;
                } else {
                    error_log('A render frame hook failed while leaving: ' . $e->getMessage());
                }
            }
        }
        if ($parent !== null) {
            foreach ($frame->skippedWrites() as $write) {
                $parent->recordSkippedWrite($write);
            }
        }

        $balanced = self::$top === $frame && count(self::$frames) === $depth + 1;
        if ($balanced) {
            array_pop(self::$frames);
            self::$top = $parent;
        } else {
            // Close this frame and anything left open above it; a reset stack stays as it is.
            $at = array_search($frame, self::$frames, true);
            if ($at !== false) {
                self::$frames = array_slice(self::$frames, 0, $at);
                self::$top = self::$frames === [] ? null : self::$frames[array_key_last(self::$frames)];
            }
            $problem = new \LogicException(
                "The render frame stack is out of balance: run() opened a frame at depth {$depth}, and at its end the depth is "
                . count(self::$frames) . ' with another frame on top (a frame was reset or left open inside the render).',
                0,
                $failure,
            );
            if ($debug) {
                throw $problem;
            }
            // Repaired quietly for the visitor, but never unseen: the frame's mail was dropped.
            error_log($problem->getMessage());
            \assert($balanced, $problem);
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    /**
     * Replaces the current frame's context with what $with returns, for the rest of that frame
     * (not for an outer frame, and only until the end of an enclosing within()).
     *
     * @param \Closure(RenderContext): RenderContext $with
     *
     * @throws NoRenderContext outside every render
     * @throws \LogicException (since 0.81.0) when the new context would loosen the frame or
     *                         leave its role view (see the class docblock); the frame keeps its context
     */
    public static function narrow(\Closure $with): void
    {
        $frame = self::$top ?? throw self::missing('narrow');
        $narrowed = $with($frame->context);
        if (!$narrowed instanceof RenderContext) {
            throw new \TypeError('Contexts::narrow() needs a closure that returns a RenderContext, got ' . get_debug_type($narrowed) . '.');
        }
        self::admit($frame, $narrowed, 'narrow');
        $frame->context = $narrowed;
        if (!$narrowed->isPublicDefault()) {
            $frame->noteDeparture();
        }
    }

    /**
     * Runs $fn under $ctx in the current frame (what it queues and collects stays in the frame)
     * and puts the previous context back afterwards, also when $fn throws. Opens no frame, so no
     * onEnter() hook runs.
     *
     * @template R
     * @param \Closure(): R $fn
     * @return R
     *
     * @throws NoRenderContext outside every render
     * @throws \LogicException (since 0.81.0) when $ctx would loosen the frame or leave its role
     *                         view (see the class docblock); $fn does not run
     */
    public static function within(RenderContext $ctx, \Closure $fn): mixed
    {
        $frame = self::$top ?? throw self::missing('within');
        self::admit($frame, $ctx, 'within');
        $previous = $frame->context;
        $frame->context = $ctx;
        if (!$ctx->isPublicDefault()) {
            $frame->noteDeparture();
        }
        try {
            return $fn();
        } finally {
            $frame->context = $previous;
        }
    }

    /** @throws NoRenderContext outside every render (the message names the caller) */
    public static function current(): RenderContext
    {
        return (self::$top ?? throw self::missing('current'))->context;
    }

    /** The current context, or null outside every render (only for code with a documented default). */
    public static function peek(): ?RenderContext
    {
        return self::$top?->context;
    }

    /** @throws NoRenderContext outside every render */
    public static function frame(): RenderFrame
    {
        return self::$top ?? throw self::missing('frame');
    }

    /** How many frames are open (0 outside every render). */
    public static function depth(): int
    {
        return count(self::$frames);
    }

    /**
     * Closes every frame, forgets every onEnter() hook and turns debug mode off. Deprecations
     * keep their own reset(): which call sites warned is per process, not per render.
     *
     * @internal tests only
     */
    public static function reset(): void
    {
        self::$frames = [];
        self::$top = null;
        self::$enterHooks = [];
        self::$debug = false;
    }

    /**
     * Debug mode: an unbalanced frame stack throws a LogicException (otherwise it is repaired,
     * its message is logged with error_log(), and assert() reports it where assertions are on). Off by default; the Application sets
     * it at boot from its debug level.
     *
     * @internal Application
     */
    public static function debug(bool $on): void
    {
        self::$debug = $on;
    }

    /**
     * Sends what the current frame queued and runs its after-response work now, round after
     * round while that work queues more (the owner of the outermost frame calls it after the
     * response went out). What is left after 10 rounds is logged and dropped. A channel that
     * fails does not stop the rounds: the after-response work still runs, and the first failure
     * is thrown at the end (the owner of a request logs it).
     *
     * @throws NoRenderContext outside every render
     * @throws \LogicException unless the current frame is the outermost one and delivers
     */
    public static function deliverNow(): void
    {
        self::deliverOutermost((self::$top ?? throw self::missing('deliverNow'))->outbox());
    }

    /**
     * Sends what the current frame queued now and drops its after-response work, which never
     * runs: for the owner of a request that has no "after the response" (an in-process
     * Application::handle()) or that failed (mail queued before the failure still goes out,
     * as data written before it stays written).
     *
     * @since 0.81.0
     *
     * @throws NoRenderContext outside every render
     * @throws \LogicException unless the current frame is the outermost one and delivers, or
     *                         when a channel failed (the other channels were sent)
     */
    public static function deliverQueued(): void
    {
        $outbox = (self::$top ?? throw self::missing('deliverQueued'))->outbox();
        try {
            $outbox->deliver();
        } finally {
            $outbox->dropAfterResponse();
        }
    }

    /**
     * Adds $hook, called each time run() opens a frame, with the new context and frame (inside
     * the frame). The closure it returns, if any, runs when that frame closes, also after a
     * failure. Hooks are kept for the whole process (until reset()). A hook given a $name is
     * kept once: registering that name again replaces it in its place, so code that runs once
     * per app boot (core's superglobal adapter of internal renders) never adds it twice.
     *
     * @param \Closure(RenderContext, RenderFrame): ?\Closure $hook
     * @param string|null $name since 0.81.0: one hook per name
     */
    public static function onEnter(\Closure $hook, ?string $name = null): void
    {
        if ($name === null) {
            self::$enterHooks[] = $hook;

            return;
        }
        self::$enterHooks[$name] = $hook;
    }

    /** Applies the context's outbox policy to a frame whose render returned. */
    private static function close(RenderFrame $frame, ?RenderFrame $parent): void
    {
        $outbox = $frame->outbox();
        match ($frame->context->outbox) {
            OutboxPolicy::Discard => $outbox->discard(),
            OutboxPolicy::Capture => $outbox->keepForCapture(),
            OutboxPolicy::Deliver => $parent !== null
                ? $outbox->moveTo($parent->outbox())
                : self::deliverOutermost($outbox),
        };
    }

    private static function deliverOutermost(FrameOutbox $outbox): void
    {
        $rounds = 0;
        $failure = null;
        do {
            try {
                $outbox->deliver();
            } catch (\Throwable $e) {
                // runAfterResponse() refuses at once where deliver() was refused (not the outermost frame).
                if ($failure === null) {
                    $failure = $e;
                } else {
                    error_log('An outbox channel failed: ' . $e->getMessage());
                }
            }
            $outbox->runAfterResponse();
            [$items, $work] = $outbox->pending();
        } while (($items > 0 || $work > 0) && ++$rounds < self::DELIVERY_ROUNDS);

        if ($items > 0 || $work > 0) {
            error_log(sprintf(
                'After %d rounds of after-response work, %d queued %s and %d %s of after-response work were left and dropped.',
                self::DELIVERY_ROUNDS,
                $items,
                $items === 1 ? 'item' : 'items',
                $work,
                $work === 1 ? 'piece' : 'pieces',
            ));
            $outbox->discard();
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Refuses a context that would let $frame send or write more than run() opened it with, or
     * that swaps its role viewer for a real or an anonymous one.
     *
     * @throws \LogicException
     */
    private static function admit(RenderFrame $frame, RenderContext $next, string $method): void
    {
        if ($frame->context->viewer->isSynthetic() && !$next->viewer->isSynthetic()) {
            throw new \LogicException("Contexts::{$method}() cannot leave the role view this frame renders as (view as role): a render as someone else opens its own frame with Contexts::run().");
        }
        if ($next->outbox === OutboxPolicy::Deliver && $frame->openedOutbox !== OutboxPolicy::Deliver) {
            throw new \LogicException("Contexts::{$method}() cannot make a frame deliver that was opened with outbox policy {$frame->openedOutbox->name}: its caller decides what the render may send.");
        }
        if ($next->writes === WritePolicy::Allow && $frame->openedWrites !== WritePolicy::Allow) {
            throw new \LogicException("Contexts::{$method}() cannot make a frame write that was opened with write policy {$frame->openedWrites->name}: its caller decides what the render may write.");
        }
    }

    private static function missing(string $method): NoRenderContext
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        return NoRenderContext::at($method, $caller['file'] ?? null, $caller['line'] ?? null);
    }
}
