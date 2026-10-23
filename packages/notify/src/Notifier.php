<?php

declare(strict_types=1);

namespace Preflow\Notify;

/**
 * Sends a message through the transport configured for its channel
 * ('channels' section of config/notify.php).
 */
final class Notifier
{
    /** Most messages one flushDeferred() call sends, so an onFailure that re-queues cannot loop forever. */
    public const FLUSH_LIMIT = 100;

    /** @var list<array{Message, ?\Closure}> */
    private array $deferred = [];

    /**
     * @param array<string, array{transport?: string, from?: string|null}> $channels
     * @param \Psr\Log\LoggerInterface|\Closure():(\Psr\Log\LoggerInterface|null)|null $logger
     *        receives deferred failures that have no callback; a closure is resolved at failure
     *        time, so a logger bound late (by a provider) is still used
     * @param bool $defer false makes sendLater() send at once (tests, debugging)
     */
    public function __construct(
        private readonly TransportFactory $transports,
        private readonly array $channels,
        private readonly \Psr\Log\LoggerInterface|\Closure|null $logger = null,
        private readonly bool $defer = true,
    ) {}

    /**
     * Queue a message; it goes out when flushDeferred() runs (core does that after the
     * response is sent). A failure never throws: it goes to $onFailure, else to the
     * logger, else to error_log (without addresses).
     *
     * @param \Closure(\Throwable): void|null $onFailure
     */
    public function sendLater(Message $message, ?\Closure $onFailure = null): void
    {
        if (!$this->defer) {
            $this->deliver($message, $onFailure);

            return;
        }

        $this->deferred[] = [$message, $onFailure];
    }

    /** Sends the queue in FIFO order, each message isolated. Returns how many were sent. */
    public function flushDeferred(): int
    {
        $sent = 0;
        $handled = 0;
        while ($this->deferred !== []) {
            if ($handled++ >= self::FLUSH_LIMIT) {
                $this->log('Deferred notifications: flush limit reached, ' . count($this->deferred) . ' left unsent.');
                break;
            }
            [$message, $onFailure] = array_shift($this->deferred);
            if ($this->deliver($message, $onFailure)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function deliver(Message $message, ?\Closure $onFailure): bool
    {
        try {
            $this->send($message);

            return true;
        } catch (\Throwable $e) {
            $this->reportFailure($e, $onFailure);

            return false;
        }
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

    /** @throws TransportException */
    public function send(Message $message): SentMessage
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

        return $transport->send($message);
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
