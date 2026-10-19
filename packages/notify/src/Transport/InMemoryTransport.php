<?php

declare(strict_types=1);

namespace Preflow\Notify\Transport;

use Preflow\Notify\Message;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Transport;

/** Keeps sent messages in memory, for tests. */
final class InMemoryTransport implements Transport
{
    /** @var list<SentMessage> */
    private array $sent = [];

    public function __construct(
        private readonly string $name = 'memory',
    ) {}

    public function supports(Message $message): bool
    {
        return true;
    }

    public function send(Message $message): SentMessage
    {
        return $this->sent[] = new SentMessage($message, $this->name);
    }

    /** @return list<SentMessage> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
