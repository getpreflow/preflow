<?php

declare(strict_types=1);

namespace Preflow\Notify;

use Preflow\Notify\Deferred\Deferred;
use Preflow\Notify\Deferred\DeferredQueue;
use Preflow\Notify\Deferred\InMemoryQueue;

/**
 * Sends a message through the transport configured for its channel
 * ('channels' section of config/notify.php).
 *
 * What sendLater() queues waits in a DeferredQueue (since 0.81.0): notify's own InMemoryQueue
 * unless one is given. The queue's policy() also decides whether send() delivers: an app whose
 * queue joins its render's outbox (core binds one) sends nothing from a render that must not send
 * (a preview, an audit), and lists what it would have sent where the render captures.
 *
 * With preflow/core, a Notifier the app binds itself (a provider's) must be given the container's
 * queue, `$container->get(DeferredQueue::class)`: with notify's own queue it would send from
 * internal renders and previews. Core checks this after the providers booted (it throws in debug
 * mode and logs otherwise); queue() is what it compares.
 */
final class Notifier
{
    /** Most messages one flushDeferred() call sends, so an onFailure that re-queues cannot loop forever. */
    public const FLUSH_LIMIT = 100;

    private readonly DeferredQueue $queue;

    /** How many messages deliver() sent, so flushDeferred() can say how many it sent. */
    private int $delivered = 0;

    /**
     * @param array<string, array{transport?: string, from?: string|null}> $channels
     * @param \Psr\Log\LoggerInterface|\Closure():(\Psr\Log\LoggerInterface|null)|null $logger
     *        receives deferred failures that have no callback; a closure is resolved at failure
     *        time, so a logger bound late (by a provider) is still used
     * @param bool $defer false makes sendLater() send at once (tests, debugging) where the queue's
     *        policy delivers
     * @param DeferredQueue|null $queue since 0.81.0: where sendLater() queues; null is notify's
     *        own InMemoryQueue (at most FLUSH_LIMIT sends per flush)
     */
    public function __construct(
        private readonly TransportFactory $transports,
        private readonly array $channels,
        private readonly \Psr\Log\LoggerInterface|\Closure|null $logger = null,
        private readonly bool $defer = true,
        ?DeferredQueue $queue = null,
    ) {
        $this->queue = $queue ?? new InMemoryQueue($this->deliver(...), self::FLUSH_LIMIT, $this->log(...));
    }

    /**
     * The queue sendLater() queues in (the one given, else notify's own).
     *
     * @since 0.81.0
     */
    public function queue(): DeferredQueue
    {
        return $this->queue;
    }

    /**
     * Queue a message; it goes out when the queue sends it: notify's own queue at flushDeferred()
     * (core does that after the response is sent), core's with the render's outbox. A failure
     * never throws: it goes to $onFailure, else to the logger, else to error_log (without
     * addresses). Where the queue's policy discards, the message is dropped and $onFailure is
     * never called; where it captures, it is listed, never sent.
     *
     * @param \Closure(\Throwable): void|null $onFailure
     */
    public function sendLater(Message $message, ?\Closure $onFailure = null): void
    {
        $item = new Deferred($message, $message->channel(), $onFailure, self::summary($message));
        if (!$this->defer && $this->queue->policy() === DeferredQueue::DELIVER) {
            $this->deliver($item);

            return;
        }

        $this->queue->push($item);
    }

    /**
     * Runs $work and returns what it returns; a message it queues with sendLater() is dropped,
     * never sent (also when sendLater() would send at once), and its onFailure is not called;
     * since 0.81.0 send() sends nothing either. The queue from before stays as it was and goes
     * out at the next flush. Calls nest. Delegates to the queue's discarding() (since 0.81.0).
     * For notify on its own. With core it was removed in 0.83 inside a render (core's queue
     * throws a LogicException there): a render that must send nothing runs in a discarding render
     * context, Application::render() or Contexts::run() with RenderContext::discarding().
     *
     * @since 0.74
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function discardingQueued(\Closure $work): mixed
    {
        return $this->queue->discarding($work);
    }

    /**
     * Sends what the queue holds and may send now, in FIFO order, each message isolated. Returns
     * how many were sent. With core's queue, that is the mail the current request or command
     * queued, when it is the outermost one and delivers; a nested render's goes with it.
     */
    public function flushDeferred(): int
    {
        $before = $this->delivered;
        $this->queue->flush();

        return $this->delivered - $before;
    }

    /**
     * Sends a queued item now: a queue calls it when the item may go out. A failure never throws:
     * it goes to the item's onFailure, else to the logger, else to error_log (without addresses).
     * True when it was sent.
     *
     * For queues only: it sends whatever the render's policy is (a preview's or an audit's
     * included), because the queue has already decided that the item may go out. Code that wants
     * to send uses send() or sendLater(), which follow the policy.
     *
     * @since 0.81.0
     */
    public function deliver(Deferred $item): bool
    {
        try {
            $this->transmit($item->message);
            $this->delivered++;

            return true;
        } catch (\Throwable $e) {
            $this->reportFailure($e, $item->onFailure);

            return false;
        }
    }

    /** One line for a list of what a render would have sent: the channel, the recipients and the subject. */
    private static function summary(Message $message): string
    {
        return match (true) {
            $message instanceof Email => 'Email to ' . implode(', ', $message->to) . ': ' . $message->subject,
            $message instanceof Sms => 'SMS to ' . $message->to,
            default => 'A message on channel ' . $message->channel(),
        };
    }

    private function reportFailure(\Throwable $e, ?\Closure $onFailure): void
    {
        if ($onFailure !== null) {
            try {
                $onFailure($e);

                return;
            } catch (\Throwable $handlerError) {
                // Generic on purpose: messages can carry addresses or DSNs.
                $this->log('Deferred notification failure handler threw (' . $handlerError::class . ').');

                return;
            }
        }

        $this->log('Deferred notification failed (' . $e::class . ').');
    }

    private function log(string $text): void
    {
        try {
            $logger = $this->logger instanceof \Closure ? ($this->logger)() : $this->logger;
            if ($logger instanceof \Psr\Log\LoggerInterface) {
                $logger->error($text);

                return;
            }
        } catch (\Throwable) {
            // fall through to error_log
        }
        error_log($text);
    }

    /**
     * Sends at once, so the caller knows whether it went out. Since 0.81.0, where the queue's
     * policy is not DELIVER (a preview, an audit), the message is checked as for sending but not
     * sent: the queue records it (a capture lists it) and the answer is as if it was sent, so the
     * caller takes the path it takes on a real visit.
     *
     * @throws TransportException
     */
    public function send(Message $message): SentMessage
    {
        if ($this->queue->policy() === DeferredQueue::DELIVER) {
            return $this->transmit($message);
        }
        [$message, $name] = $this->prepare($message);
        $this->queue->record(self::summary($message));

        return new SentMessage($message, $name);
    }

    /** @throws TransportException */
    private function transmit(Message $message): SentMessage
    {
        [$message, , $transport] = $this->prepare($message);

        return $transport->send($message);
    }

    /**
     * The message with its channel's defaults, and the name of the transport that sends it and
     * the transport.
     *
     * @return array{Message, string, Transport}
     *
     * @throws TransportException
     */
    private function prepare(Message $message): array
    {
        $channel = $message->channel();
        $config = $this->channels[$channel] ?? [];
        $name = $config['transport'] ?? null;

        if (!is_string($name) || $name === '') {
            throw new TransportException(sprintf(
                'No transport configured for channel "%s" (config/notify.php → channels.%s.transport).',
                $channel,
                $channel,
            ));
        }

        $message = $this->applyDefaults($message, $config);
        $transport = $this->transports->get($name);

        if (!$transport->supports($message)) {
            $short = substr(strrchr('\\' . $message::class, '\\'), 1);
            throw new TransportException(sprintf('Transport "%s" cannot send %s messages.', $name, $short));
        }

        return [$message, $name, $transport];
    }

    /** @param array<string, mixed> $config */
    private function applyDefaults(Message $message, array $config): Message
    {
        $from = $config['from'] ?? null;
        if (!is_string($from) || $from === '') {
            return $message;
        }

        if (($message instanceof Email || $message instanceof Sms) && $message->from === null) {
            return $message->withFrom($from);
        }

        return $message;
    }
}
