<?php

declare(strict_types=1);

namespace Preflow\Notify\Bridge\SymfonyMailer;

use Preflow\Notify\Email;
use Preflow\Notify\Message;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Transport;
use Preflow\Notify\TransportException;
use Symfony\Component\Mailer\Transport as SymfonyTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as SymfonyEmail;

/** Sends Email messages through symfony/mailer, configured by DSN (smtp://…, or any Symfony mailer bridge). */
final class SymfonyMailerTransport implements Transport
{
    private ?TransportInterface $transport = null;

    public function __construct(
        #[\SensitiveParameter] private readonly string $dsn,
        private readonly string $name,
    ) {}

    public function supports(Message $message): bool
    {
        return $message instanceof Email;
    }

    public function send(Message $message): SentMessage
    {
        if (!$message instanceof Email) {
            throw new TransportException(sprintf('Transport "%s" can only send Email messages.', $this->name));
        }
        if ($this->dsn === '') {
            throw new TransportException(sprintf('Transport "%s": dsn is empty (set it in .env).', $this->name));
        }
        if ($message->from === null) {
            throw new TransportException(sprintf(
                'Transport "%s": email has no from address; set channels.email.from in config/notify.php.',
                $this->name,
            ));
        }

        try {
            $this->transport ??= SymfonyTransport::fromDsn($this->dsn);
            $sent = $this->transport->send($this->toSymfonyEmail($message));
        } catch (\Throwable $e) {
            throw TransportException::fromProvider($this->name, $e);
        }

        return new SentMessage($message, $this->name, $sent?->getMessageId());
    }

    public function toSymfonyEmail(Email $email): SymfonyEmail
    {
        $symfony = (new SymfonyEmail())
            ->to(...self::addresses($email->to))
            ->subject($email->subject)
            ->text($email->text);

        if ($email->from !== null) {
            $symfony->from(Address::create($email->from));
        }
        if ($email->html !== null) {
            $symfony->html($email->html);
        }
        if ($email->replyTo !== []) {
            $symfony->replyTo(...self::addresses($email->replyTo));
        }
        if ($email->cc !== []) {
            $symfony->cc(...self::addresses($email->cc));
        }
        if ($email->bcc !== []) {
            $symfony->bcc(...self::addresses($email->bcc));
        }

        foreach ($email->attachments as $attachment) {
            if ($attachment->path !== null) {
                $symfony->attachFromPath($attachment->path, $attachment->name, $attachment->mime);
            } else {
                $symfony->attach((string) $attachment->contents, $attachment->name, $attachment->mime);
            }
        }

        return $symfony;
    }

    /**
     * @param list<string> $list
     * @return list<Address>
     */
    private static function addresses(array $list): array
    {
        return array_map(static fn (string $address) => Address::create($address), $list);
    }
}
