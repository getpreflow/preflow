<?php

declare(strict_types=1);

namespace Preflow\Notify;

final readonly class SentMessage
{
    /**
     * @param array<string, string> $failures transports a failover tried and fell back from,
     *                                        name => masked error message
     */
    public function __construct(
        public Message $message,
        public string $transport,
        public ?string $messageId = null,
        public array $failures = [],
    ) {}
}
