<?php

declare(strict_types=1);

namespace Preflow\Notify;

/**
 * An email message. Addresses are plain strings: "a@b.test" or "Name <a@b.test>".
 */
final readonly class Email implements Message
{
    /** @var list<string> */
    public array $to;

    /** @var list<string> */
    public array $replyTo;

    /** @var list<string> */
    public array $cc;

    /** @var list<string> */
    public array $bcc;

    /** @var list<Attachment> */
    public array $attachments;

    /**
     * @param string|list<string>      $to
     * @param string|list<string>|null $replyTo
     * @param string|list<string>      $cc
     * @param string|list<string>      $bcc
     * @param list<Attachment>         $attachments
     */
    public function __construct(
        string|array $to,
        public string $subject,
        public string $text,
        public ?string $html = null,
        public ?string $from = null,
        string|array|null $replyTo = null,
        string|array $cc = [],
        string|array $bcc = [],
        array $attachments = [],
    ) {
        $this->to = self::addresses($to);
        if ($this->to === []) {
            throw new \InvalidArgumentException('An email needs at least one recipient.');
        }

        $this->replyTo = self::addresses($replyTo ?? []);
        $this->cc = self::addresses($cc);
        $this->bcc = self::addresses($bcc);

        self::assertSingleLine($subject, 'subject');
        if ($from !== null) {
            self::assertSingleLine($from, 'from');
        }

        foreach ($attachments as $attachment) {
            if (!$attachment instanceof Attachment) {
                throw new \InvalidArgumentException('Attachments must be ' . Attachment::class . ' instances.');
            }
        }
        $this->attachments = array_values($attachments);
    }

    public function channel(): string
    {
        return 'email';
    }

    public function withFrom(string $from): self
    {
        return new self(
            $this->to,
            $this->subject,
            $this->text,
            $this->html,
            $from,
            $this->replyTo,
            $this->cc,
            $this->bcc,
            $this->attachments,
        );
    }

    /**
     * @param string|list<string> $value
     * @return list<string>
     */
    private static function addresses(string|array $value): array
    {
        $list = is_string($value) ? [$value] : array_values($value);
        foreach ($list as $address) {
            if (!is_string($address) || trim($address) === '') {
                throw new \InvalidArgumentException('Email addresses must be non-empty strings.');
            }
            self::assertSingleLine($address, 'address');
        }

        return $list;
    }

    private static function assertSingleLine(string $value, string $what): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new \InvalidArgumentException("Email {$what} must not contain line breaks.");
        }
    }
}
