<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

/**
 * What a render asked of PHP's process-wide limits (a longer time limit, going on after the
 * browser closed the tab), recorded on its frame for the debug toolbar. The limits themselves
 * are process-wide and cannot be scoped to a render, so they stay an edge call; this only says
 * which render asked. A frame slot (RenderFrame::slot()). The debug toolbar shows only the request
 * frame's: a render in a frame of its own records on that frame, which it does not see.
 *
 * @since 0.81.0
 */
final class RequestLimits
{
    /** @var list<array{seconds: int, before: int, raised: bool}> each time limit asked for: seconds, the limit before (0: none), whether it was raised */
    public array $timeLimits = [];

    /** Whether the render asked to go on when the client goes away (ignore_user_abort). */
    public bool $ignoresUserAbort = false;

    public function timeLimit(int $seconds, int $before, bool $raised): void
    {
        $this->timeLimits[] = ['seconds' => $seconds, 'before' => $before, 'raised' => $raised];
    }
}
