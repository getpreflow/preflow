<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * Deprecation notices that do not flood: a shim called in a loop warns once per call site and
 * process, and still does its work every time.
 *
 * @since 0.81.0
 */
final class Deprecations
{
    /** @var array<string, true> the call sites already reported, by message and file:line */
    private static array $reported = [];

    /** How many expected() stretches are open (they nest). */
    private static int $expecting = 0;

    /**
     * Emits $message as E_USER_DEPRECATED (silenced with @, so it reaches error handlers and
     * logs but is not printed), once per call site per process.
     *
     * @param int $depth which line counts as the call site: 1 is the line calling once(), 2 (the
     *                   default) the line calling the method that calls once(), the shim's caller
     */
    public static function once(string $message, int $depth = 2): void
    {
        if (self::$expecting > 0) {
            return;
        }
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, max(1, $depth));
        $frame = $trace[max(1, $depth) - 1] ?? $trace[array_key_last($trace)];
        $key = $message . "\0" . ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? 0);
        if (isset(self::$reported[$key])) {
            return;
        }
        self::$reported[$key] = true;
        @trigger_error($message, E_USER_DEPRECATED);
    }

    /**
     * Runs $fn, whose deprecated calls are deliberate (a test that exercises a shim on purpose):
     * while it runs, once() neither reports nor records a call site, so the calls are not
     * counted as the caller's and use up no notice. Nests; ends also when $fn throws.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     *
     * @internal tests only
     * @since 0.82.0
     */
    public static function expected(\Closure $fn): mixed
    {
        self::$expecting++;
        try {
            return $fn();
        } finally {
            self::$expecting--;
        }
    }

    /**
     * Forgets which call sites warned, so a test sees a shim's notice whatever ran before it. Kept
     * apart from Contexts::reset(): which call sites warned is per process, not per render.
     *
     * @internal tests only
     */
    public static function reset(): void
    {
        self::$reported = [];
    }
}
