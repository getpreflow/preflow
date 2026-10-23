<?php

declare(strict_types=1);

namespace Preflow\Notify;

/**
 * Sends a message through the transport configured for its channel
 * ('channels' section of config/notify.php).
 */
final class Notifier
{
    /**
     * @param array<string, array{transport?: string, from?: string|null}> $channels
     */
    /** @var list<array{Message, ?\Closure}> */
    private array $deferred = [];

    /**
     * @param \Psr\Log\LoggerInterface|null $logger receives deferred failures that have no callback
     * @param bool $defer false makes sendLater() send at once (tests, debugging)
     */
    public function __construct(
        private readonly TransportFactory $transports,
        private readonly array $channels,
        private readonly ?\Psr\Log\LoggerInterface $logger = null,
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
        while ($this->deferred !== []) {
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
        try {
            if ($onFailure !== null) {
                $onFailure($e);

                return;
            }
            // Generic on purpose: exception messages can carry addresses or DSNs.
            $text = 'Deferred notification failed (' . $e::class . ').';
            if ($this->logger !== null) {
                $this->logger->error($text);
            } else {
                error_log($text);
            }
        } catch (\Throwable) {
            // A broken failure handler must not break the others or the request.
        }
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
