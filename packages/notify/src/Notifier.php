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
    public function __construct(
        private readonly TransportFactory $transports,
        private readonly array $channels,
    ) {}

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
