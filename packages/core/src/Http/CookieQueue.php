<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\OutboxPolicy;
use Psr\Http\Message\ResponseInterface;

/**
 * Set-Cookie values queued during a render (e.g. remember-me cookies of preflow/auth). The
 * middleware that queues them applies them to its response; the Kernel applies what is left to
 * an error response, which bypasses the pipeline. Applying empties the queue.
 *
 * Since 0.81 each render frame has its own queue (a frame slot): the one the container gives,
 * ofCurrentFrame(), works on the queue of the frame current at each call, so a render in its own
 * frame (Application::render()) never sees or takes the cookies of the request around it.
 * Since 0.81.1 a request's frame that delivers sets what it queued, with or without auth or a
 * session: the Kernel applies the queue to the route's response and to the pipeline's answer
 * (ResolveUserMiddleware, on a request with a session, may have set them first), and to the error
 * response. A capturing render reports what it left (CookieChannel), a delivering one hands it to
 * the queue of the frame around it, a discarding one drops it. A queue made with `new` is a plain
 * list of its own.
 */
final class CookieQueue
{
    /** @var list<string> */
    private array $cookies = [];

    private bool $frameBound = false;

    /**
     * The queue that follows the render frames: each call works on the current frame's own queue,
     * and on a list of its own outside every frame.
     *
     * @since 0.81.0
     */
    public static function ofCurrentFrame(): self
    {
        $queue = new self();
        $queue->frameBound = true;

        return $queue;
    }

    /**
     * Queues $setCookie for the current frame's response. Since 0.81.0, inside a stretch of a
     * frame that would send it but now does not (a discarding or capturing Contexts::within(), or
     * a render delivering into one: RenderFrame::outboxPolicy()), it never reaches a response:
     * dropped under Discard, kept in the frame's outbox as a captured 'cookies' item under Capture.
     */
    public function queue(string $setCookie): void
    {
        if ($this->frameBound && Contexts::depth() > 0) {
            $frame = Contexts::frame();
            $policy = $frame->outboxPolicy();
            if ($frame->openedOutbox === OutboxPolicy::Deliver && $policy !== OutboxPolicy::Deliver) {
                if ($policy === OutboxPolicy::Capture) {
                    $frame->outbox()->channel(new CookieChannel());
                    $frame->outbox()->queue(new QueuedCookie($setCookie), CookieChannel::NAME);
                }

                return;
            }
        }
        $this->target()->cookies[] = $setCookie;
    }

    /** Whether a cookie named $name is queued (not applied yet). */
    public function has(string $name): bool
    {
        foreach ($this->target()->cookies as $cookie) {
            if (str_starts_with($cookie, $name . '=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The queued values, emptying the queue.
     *
     * @return list<string>
     */
    public function pull(): array
    {
        $queue = $this->target();
        [$cookies, $queue->cookies] = [$queue->cookies, []];

        return $cookies;
    }

    /**
     * Runs $render with the queue set aside: it starts empty, what the render queues is dropped,
     * and the values queued before are back afterwards, also when $render throws. Inside a frame
     * it runs in a Contexts::within() of the same context, so it stays in that frame.
     *
     * @deprecated 0.81, removed in 0.83: a render in its own frame (Application::render()) starts
     *             with its own cookies
     *
     * @template T
     * @param \Closure(): T $render
     * @return T
     */
    public function isolated(\Closure $render): mixed
    {
        Deprecations::once('Preflow\Core\Http\CookieQueue::isolated() is deprecated since 0.81 and is removed in 0.83; use Application::render() (a render in its own frame starts with its own cookies).');
        $queue = $this->target();
        [$outer, $queue->cookies] = [$queue->cookies, []];
        try {
            return Contexts::depth() === 0 ? $render() : Contexts::within(Contexts::current(), $render);
        } finally {
            $queue->cookies = $outer;
        }
    }

    public function apply(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->pull() as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }

        return $response;
    }

    /** The queue the calls work on: the current frame's for a frame-bound queue, else this one. */
    private function target(): self
    {
        if (!$this->frameBound) {
            return $this;
        }

        return Contexts::slot(self::class, static fn (): self => new self()) ?? $this;
    }
}
