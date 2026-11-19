<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * The one holder of the current render context. Three tools change it, and only these:
 * - run(): a new render in a new frame (frames nest; the outer one is back afterwards);
 * - narrow(): the rest of the current frame (middleware that resolved the user or the locale);
 * - within(): a stretch of the current frame (a template preview in the visitor's texts), with
 *   the previous context back afterwards, also when the stretch throws.
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

    /** @var list<\Closure(RenderContext, RenderFrame): (\Closure(): void)|null> */
    private static array $enterHooks = [];

    /**
     * Runs $render in a new frame under $ctx and returns what it returns. The frame always
     * closes, also when $render throws: on success its outbox follows $ctx's OutboxPolicy as narrowed by then
     * (see FrameOutbox); when $render throws, its outbox is dropped. Its skipped writes always
     * move to the parent frame, so the run that opened a frame reports its nested renders' too.
     *
     * The onEnter() hooks run after the frame opened, their leave closures (in reverse order)
     * after the outbox policy and before the frame is gone. With APP_DEBUG on, a frame stack
     * that is not as run() left it (a frame left open, a reset inside a render) throws a
     * LogicException; with assertions on, assert() reports it too.
     *
     * @template R
     * @param \Closure(): R $render
     * @return R
     */
    public static function run(RenderContext $ctx, \Closure $render): mixed
    {
        $depth = count(self::$frames);
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
            self::close($frame, $parent);
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
            \assert($balanced, $problem);
            if (self::debug()) {
                throw $problem;
            }
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
     */
    public static function narrow(\Closure $with): void
    {
        $frame = self::$top ?? throw self::missing('narrow');
        $narrowed = $with($frame->context);
        if (!$narrowed instanceof RenderContext) {
            throw new \TypeError('Contexts::narrow() needs a closure that returns a RenderContext, got ' . get_debug_type($narrowed) . '.');
        }
        $frame->context = $narrowed;
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
     */
    public static function within(RenderContext $ctx, \Closure $fn): mixed
    {
        $frame = self::$top ?? throw self::missing('within');
        $previous = $frame->context;
        $frame->context = $ctx;
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
     * Closes every frame and forgets every onEnter() hook.
     *
     * @internal tests only
     */
    public static function reset(): void
    {
        self::$frames = [];
        self::$top = null;
        self::$enterHooks = [];
    }

    /**
     * Adds $hook, called each time run() opens a frame, with the new context and frame (inside
     * the frame). The closure it returns, if any, runs when that frame closes, also after a
     * failure. Hooks are kept for the whole process: register one once (core uses it for the
     * superglobal adapter of internal renders).
     *
     * @param \Closure(RenderContext, RenderFrame): ?\Closure $hook
     */
    public static function onEnter(\Closure $hook): void
    {
        self::$enterHooks[] = $hook;
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
        $outbox->deliver();
        $outbox->runAfterResponse();
    }

    private static function missing(string $method): NoRenderContext
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        return NoRenderContext::at($method, $caller['file'] ?? null, $caller['line'] ?? null);
    }

    private static function debug(): bool
    {
        $value = getenv('APP_DEBUG');

        return $value !== false && !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
    }
}
