<?php

declare(strict_types=1);

namespace Preflow\Notify;

interface Transport
{
    public function supports(Message $message): bool;

    /** @throws TransportException */
    public function send(Message $message): SentMessage;
}
