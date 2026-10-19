<?php

declare(strict_types=1);

namespace Preflow\Notify\Bridge\SymfonyNotifier;

use Preflow\Notify\Message;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Sms;
use Preflow\Notify\Transport;
use Preflow\Notify\TransportException;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport as SymfonyTransport;
use Symfony\Component\Notifier\Transport\TransportInterface;

/** Sends Sms messages through symfony/notifier, configured by DSN (twilio://…, vonage://…, any installed SMS bridge). */
final class SymfonyNotifierSmsTransport implements Transport
{
    private ?TransportInterface $transport = null;

    public function __construct(
        #[\SensitiveParameter] private readonly string $dsn,
        private readonly string $name,
    ) {}

    public function supports(Message $message): bool
    {
        return $message instanceof Sms;
    }

    public function send(Message $message): SentMessage
    {
        if (!$message instanceof Sms) {
            throw new TransportException(sprintf('Transport "%s" can only send Sms messages.', $this->name));
        }
        if ($this->dsn === '') {
            throw new TransportException(sprintf('Transport "%s": dsn is empty (set it in .env).', $this->name));
        }

        try {
            $this->transport ??= SymfonyTransport::fromDsn($this->dsn);
            $sent = $this->transport->send(new SmsMessage($message->to, $message->text, $message->from ?? ''));
        } catch (\Throwable $e) {
            throw TransportException::fromProvider($this->name, $e);
        }

        return new SentMessage($message, $this->name, $sent?->getMessageId());
    }
}
