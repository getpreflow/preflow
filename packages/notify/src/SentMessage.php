<?php

declare(strict_types=1);

namespace Preflow\Notify;

final readonly class SentMessage
{
    public function __construct(
        public Message $message,
        public string $transport,
        public ?string $messageId = null,
    ) {}
}
