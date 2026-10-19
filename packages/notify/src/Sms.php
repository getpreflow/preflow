<?php

declare(strict_types=1);

namespace Preflow\Notify;

final readonly class Sms implements Message
{
    /**
     * @param string      $to   Recipient in E.164 format, e.g. "+41790000000".
     * @param string|null $from Sender id or number; null uses the channel default.
     */
    public function __construct(
        public string $to,
        public string $text,
        public ?string $from = null,
    ) {
        if (preg_match('/^\+[1-9]\d{6,14}$/', $to) !== 1) {
            throw new \InvalidArgumentException('SMS recipients must be in E.164 format, e.g. +41790000000.');
        }
    }

    public function channel(): string
    {
        return 'sms';
    }

    public function withFrom(string $from): self
    {
        return new self($this->to, $this->text, $from);
    }
}
