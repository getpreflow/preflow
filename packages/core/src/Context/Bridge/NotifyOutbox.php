<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Bridge;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\OutboxPolicy;
use Preflow\Core\Context\RenderContext;
use Preflow\Notify\Deferred\DeferredQueue;
use Preflow\Notify\Deferred\InMemoryQueue;

/**
 * notify's deferred queue on the render context (core binds it when preflow/notify is installed):
 * what Notifier::sendLater() queues joins the current frame's outbox (channel 'mail'), and the
 * policy where it ends up decides (RenderFrame::outboxPolicy()):
 * - Deliver: it goes out when the outermost frame sends (a visitor's request after its response,
 *   also when the request failed; a command when it ends);
 * - Discard (an audit, GEO, a rebuild, verify): dropped at once;
 * - Capture (a preview, a role view): listed in the render's result, never sent.
 * send() asks policy() and records what it does not deliver the same way. Outside every render
 * (a script without a frame) notify's own InMemoryQueue holds the messages until flush().
 *
 * @since 0.81.0
 */
final class NotifyOutbox implements DeferredQueue
{
    private readonly MailChannel $channel;

    public function __construct(private readonly DeferredQueue $fallback = new InMemoryQueue())
    {
        $this->channel = new MailChannel();
    }

    public function push(\Closure $send, string $summary): void
    {
        if (self::context() === null) {
            $this->fallback->push($send, $summary);

            return;
        }
        $policy = Contexts::frame()->outboxPolicy();
        if ($policy !== OutboxPolicy::Discard) {
            $this->queue(new QueuedMail($send, $summary, $policy === OutboxPolicy::Deliver));
        }
    }

    /**
     * Sends what was queued without a frame, and, in the outermost frame while it delivers (a
     * command, a request), the mail it queued, now. Inside a nested frame, its mail goes out with
     * the frame it delivers into.
     */
    public function flush(): void
    {
        $this->fallback->flush();
        if (self::context() === null || Contexts::depth() !== 1 || Contexts::frame()->outboxPolicy() !== OutboxPolicy::Deliver) {
            return;
        }
        $outbox = Contexts::frame()->outbox();
        $mail = [];
        foreach ($outbox->drain() as $item) {
            if ($item->channel === MailChannel::NAME && $item->item instanceof QueuedMail) {
                $mail[] = $item->item;
            } else {
                $outbox->queue($item->item, $item->channel);
            }
        }
        $this->channel->deliver($mail);
    }

    /**
     * The deprecated Notifier::discardingQueued() with core: $fn runs in a discarding stretch of
     * the current frame (Contexts::within()), so what it queues or sends is dropped, also inside
     * a render it starts; the frame's policy is back afterwards.
     *
     * @deprecated 0.81, removed in 0.83: render under RenderContext::discarding() (Application::render(), Contexts::run())
     */
    public function discarding(\Closure $fn): mixed
    {
        Deprecations::once('Preflow\Notify\Notifier::discardingQueued() is deprecated since 0.81 and is removed in 0.83; use Application::render() or Contexts::run() with RenderContext::discarding().', 3);
        $ctx = self::context();

        return $ctx === null ? $this->fallback->discarding($fn) : Contexts::within($ctx->discarding(), $fn);
    }

    public function policy(): string
    {
        if (self::context() === null) {
            return $this->fallback->policy();
        }

        return match (Contexts::frame()->outboxPolicy()) {
            OutboxPolicy::Deliver => self::DELIVER,
            OutboxPolicy::Discard => self::DISCARD,
            OutboxPolicy::Capture => self::CAPTURE,
        };
    }

    public function record(string $summary): void
    {
        if (self::context() === null) {
            $this->fallback->record($summary);

            return;
        }
        if (Contexts::frame()->outboxPolicy() === OutboxPolicy::Capture) {
            $this->queue(new QueuedMail(static function (): void {}, $summary, false));
        }
    }

    private function queue(QueuedMail $mail): void
    {
        $outbox = Contexts::frame()->outbox();
        $outbox->channel($this->channel);
        $outbox->queue($mail, MailChannel::NAME);
    }

    /** The current context, or null outside every render: notify's own queue is the default there. */
    private static function context(): ?RenderContext
    {
        return Contexts::peek();
    }
}
