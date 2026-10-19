<?php

declare(strict_types=1);

namespace Preflow\Notify\Transport;

use Preflow\Notify\Message;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Transport;

/** Drops every message. */
final class NullTransport implements Transport
{
    public function __construct(
        private readonly string $name = 'null',
    ) {}

    public function supports(Message $message): bool
    {
        return true;
    }

    public function send(Message $message): SentMessage
    {
        return new SentMessage($message, $this->name);
    }
}
