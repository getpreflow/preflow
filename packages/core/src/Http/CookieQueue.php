<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\OutboxPolicy;
use Preflow\Core\Context\RenderFrame;
use Preflow\Core\Context\SettlesOnClose;
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
 * response. Since 0.82.0 the error response follows the same rule (reachesResponse()): a render
 * entry's frame (Application::render()) never sets them, also when it fails. What a frame left
 * when it closes is settled by its policy (settle(), since 0.82.0; before, only for a render):
 * a capturing frame reports it (CookieChannel), a delivering one hands it to the queue of the
 * frame around it, a discarding one drops it. A queue made with `new` is a plain list of its own,
 * set by whoever holds it.
 */
final class CookieQueue implements SettlesOnClose
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
            self::place($setCookie, Contexts::frame());

            return;
        }
        $this->target()->cookies[] = $setCookie;
    }

    /**
     * Whether this is a frame-bound queue (ofCurrentFrame()), whose calls work on the current
     * frame's own queue; false for a queue made with `new`, a plain list of its own.
     *
     * @since 0.82.0
     */
    public function isFrameBound(): bool
    {
        return $this->frameBound;
    }

    /**
     * Settles what a frame's own queue (the frame slot) holds when the frame closes, by the
     * frame's outbox policy: a delivering frame hands it to the queue of the frame around it (as
     * if queued there; the outermost frame's response took what it could, the rest is dropped),
     * a capturing frame keeps it as captured 'cookies' items in its outbox (RenderResult::wouldSend('cookies');
     * a capturing request that is part of another frame's output captures there), a discarding
     * frame drops it. A queue with nothing left makes no outbox.
     *
     * @internal Contexts, when the frame closes (SettlesOnClose)
     * @since 0.82.0
     */
    public function settle(RenderFrame $frame, ?RenderFrame $parent): void
    {
        if ($this->cookies === []) {
            return;
        }
        [$cookies, $this->cookies] = [$this->cookies, []];
        $policy = $frame->context->outbox;
        if ($policy === OutboxPolicy::Discard || ($policy === OutboxPolicy::Deliver && $parent === null)) {
            return;
        }
        foreach ($cookies as $cookie) {
            if ($policy === OutboxPolicy::Capture) {
                self::capture($cookie, $frame);
            } else {
                self::place($cookie, $parent);
            }
        }
    }

    /**
     * Queues $setCookie for $frame's response: inside a stretch of a frame that would send it but
     * now does not (RenderFrame::outboxPolicy()), it is captured or dropped at once.
     */
    private static function place(string $setCookie, RenderFrame $frame): void
    {
        $policy = $frame->outboxPolicy();
        if ($frame->openedOutbox === OutboxPolicy::Deliver && $policy !== OutboxPolicy::Deliver) {
            if ($policy === OutboxPolicy::Capture) {
                self::capture($setCookie, $frame);
            }

            return;
        }
        $frame->slot(self::class, static fn (): self => new self())->cookies[] = $setCookie;
    }

    /** Keeps $setCookie as a captured 'cookies' item in $frame's outbox. */
    private static function capture(string $setCookie, RenderFrame $frame): void
    {
        $frame->outbox()->channel(new CookieChannel());
        $frame->outbox()->queue(new QueuedCookie($setCookie), CookieChannel::NAME);
    }

    /**
     * Whether the current frame's response takes what the queue holds: outside every frame; inside
     * one, when the frame delivers (RenderFrame::outboxPolicy()) and no render entry opened it
     * (RenderFrame::$openedByRender: Application::render() settles what its frame left by the
     * frame's policy). A request asked for in-process inside another frame delivers in a frame of
     * its own, so it sets its cookies on its own response. The rule the Kernel applies on every
     * response, its error response included (pass it as Kernel's $setsQueuedCookies).
     *
     * @since 0.82.0
     */
    public function reachesResponse(): bool
    {
        // A queue of its own is a plain list that only its holder sets (on the response it
        // answers): no frame ever settles it, so whatever it holds must go on this response, or
        // it would leak into the next one.
        if (!$this->frameBound || Contexts::depth() === 0) {
            return true;
        }
        $frame = Contexts::frame();

        return !$frame->openedByRender && $frame->outboxPolicy() === OutboxPolicy::Deliver;
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
     * Whether any cookie is queued (not applied yet), without taking it: the page cache refuses a
     * page whose render left one, as it refuses one whose response sets one.
     *
     * @since 0.81.1
     */
    public function pending(): bool
    {
        return $this->target()->cookies !== [];
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
