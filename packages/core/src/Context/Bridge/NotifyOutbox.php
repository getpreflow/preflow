<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Bridge;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrameOutbox;
use Preflow\Core\Context\OutboxPolicy;
use Preflow\Core\Context\RenderContext;
use Preflow\Notify\Deferred\Deferred;
use Preflow\Notify\Deferred\DeferredQueue;
use Preflow\Notify\Deferred\InMemoryQueue;
use Preflow\Notify\Notifier;

/**
 * notify's deferred queue on the render context (core binds it when preflow/notify is installed):
 * what Notifier::sendLater() queues joins the current frame's outbox (channel 'notify'), whose
 * policy where it ends up decides (RenderFrame::outboxPolicy(), applied by the outbox):
 * - Deliver: it goes out when the outermost frame sends (a visitor's request after its response,
 *   also when the request failed; a command when it ends, also when it failed);
 * - Discard (an audit, GEO, a rebuild, verify): dropped at once;
 * - Capture (a preview, a role view): listed in the render's result, never sent.
 * send() asks policy() and records what it does not deliver the same way. Outside every render
 * (a script without a frame) notify's own InMemoryQueue holds the messages until flush().
 *
 * The messages are sent with the container's Notifier (deliver()); a Notifier the app binds
 * itself must use this queue, which core checks after boot.
 *
 * @since 0.81.0
 */
final class NotifyOutbox implements DeferredQueue
{
    private readonly NotifyChannel $channel;

    private readonly InMemoryQueue $fallback;

    /**
     * @param \Closure(): Notifier $notifier the Notifier that sends (the container's, at send time)
     * @param (\Closure(string): void)|null $log where the fallback's flush limit line goes (the app's logger; error_log when null)
     */
    public function __construct(\Closure $notifier, ?\Closure $log = null)
    {
        $send = static function (Deferred $item) use ($notifier): void {
            $notifier()->deliver($item);
        };
        $this->channel = new NotifyChannel($send);
        $this->fallback = new InMemoryQueue($send, Notifier::FLUSH_LIMIT, $log);
    }

    public function push(Deferred $item): void
    {
        if (self::context() === null) {
            $this->fallback->push($item);

            return;
        }
        $this->queue($item);
    }

    /**
     * Sends what was queued without a frame, and, in the outermost frame while it delivers (a
     * command, a request), the messages it queued, now. Inside a nested frame, its messages go
     * out with the frame it delivers into.
     */
    public function flush(): void
    {
        $this->fallback->flush();
        if (self::context() === null || Contexts::depth() !== 1 || Contexts::frame()->outboxPolicy() !== OutboxPolicy::Deliver) {
            return;
        }
        $this->channel->deliver(Contexts::frame()->outbox()->take(NotifyChannel::NAME));
    }

    /**
     * Notifier::discardingQueued() with core: removed in 0.83 inside a render, where what a render
     * sends is its context's outbox policy (Application::render() or Contexts::run() with
     * RenderContext::discarding()). Outside every render there is no frame, so notify's own queue
     * drops what $fn queues, as notify on its own does.
     *
     * @throws \LogicException inside a render
     */
    public function discarding(\Closure $fn): mixed
    {
        if (self::context() !== null) {
            throw new \LogicException('Notifier::discardingQueued() is removed with preflow/core since 0.83: render under RenderContext::discarding() (Application::render() or Contexts::run()).');
        }

        return $this->fallback->discarding($fn);
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
        // Kept only where the frame captures now: the outbox drops it under Discard.
        if (Contexts::frame()->outboxPolicy() === OutboxPolicy::Capture) {
            $this->queue(new RecordedSend($summary));
        }
    }

    /**
     * Registers notify's channel on $outbox, unless another package's channel of that name is
     * there (refused: its items would go to notify, or notify's to it).
     *
     * @throws \LogicException when a channel named 'notify' that is not notify's is registered
     */
    public function claim(FrameOutbox $outbox): void
    {
        $known = $outbox->channelNamed(NotifyChannel::NAME);
        if ($known !== null && !$known instanceof NotifyChannel) {
            throw new \LogicException("The outbox channel name 'notify' belongs to preflow/notify, but " . $known::class . ' is registered under it. Give that channel another name.');
        }
        if ($known !== $this->channel) {
            $outbox->channel($this->channel);
        }
    }

    private function queue(object $item): void
    {
        $outbox = Contexts::frame()->outbox();
        $this->claim($outbox);
        $outbox->queue($item, NotifyChannel::NAME);
    }

    /** The current context, or null outside every render: notify's own queue is the default there. */
    private static function context(): ?RenderContext
    {
        return Contexts::peek();
    }
}
