<?php

declare(strict_types=1);

namespace Preflow\Notify\Transport;

use Preflow\Notify\Email;
use Preflow\Notify\Message;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Sms;
use Preflow\Notify\Transport;
use Preflow\Notify\TransportException;

/** Appends a summary line plus the body of each message to a log file. */
final class LogTransport implements Transport
{
    public function __construct(
        private readonly string $file,
        private readonly string $name = 'log',
    ) {}

    public function supports(Message $message): bool
    {
        return true;
    }

    public function send(Message $message): SentMessage
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new TransportException(sprintf('Transport "%s": cannot create log directory %s.', $this->name, $dir));
        }

        if (@file_put_contents($this->file, $this->format($message), FILE_APPEND | LOCK_EX) === false) {
            throw new TransportException(sprintf('Transport "%s": cannot write to %s.', $this->name, $this->file));
        }

        return new SentMessage($message, $this->name);
    }

    private function format(Message $message): string
    {
        $summary = sprintf('[%s] %s', date(DATE_ATOM), $message->channel());
        $body = '';

        if ($message instanceof Email) {
            $summary .= ' to=' . implode(', ', $message->to) . ' subject=' . $message->subject;
            $body = $message->text;
        } elseif ($message instanceof Sms) {
            $summary .= ' to=' . $message->to;
            $body = $message->text;
        } else {
            $summary .= ' type=' . $message::class;
        }

        return $summary . "\n" . $body . "\n\n";
    }
}
