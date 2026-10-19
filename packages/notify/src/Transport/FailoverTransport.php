<?php

declare(strict_types=1);

namespace Preflow\Notify\Transport;

use Preflow\Notify\Message;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Transport;
use Preflow\Notify\TransportException;

/** Tries each transport in order until one succeeds; skips transports that can't send the message. */
final class FailoverTransport implements Transport
{
    /**
     * @param list<array{0: string, 1: Transport}> $transports name/transport pairs
     */
    public function __construct(
        private readonly array $transports,
        private readonly string $name = 'failover',
    ) {}

    public function supports(Message $message): bool
    {
        foreach ($this->transports as [, $transport]) {
            if ($transport->supports($message)) {
                return true;
            }
        }

        return false;
    }

    public function send(Message $message): SentMessage
    {
        $tried = [];
        $last = null;

        foreach ($this->transports as [$name, $transport]) {
            if (!$transport->supports($message)) {
                continue;
            }

            $tried[] = $name;
            try {
                return $transport->send($message);
            } catch (TransportException $e) {
                $last = $e;
            }
        }

        throw new TransportException(
            sprintf('Transport "%s": all transports failed (%s).', $this->name, implode(', ', $tried)),
            0,
            $last,
        );
    }
}
