<?php

declare(strict_types=1);

namespace Preflow\Notify\Transport;

use Preflow\Notify\Email;
use Preflow\Notify\Message;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Transport;

/**
 * Sends every email to fixed addresses instead of its recipients (stage and test
 * installations). Cc and Bcc are dropped; the original recipients lead the subject
 * and the body. Other messages (SMS) pass through unchanged.
 */
final class RedirectTransport implements Transport
{
    /**
     * @param list<string> $recipients
     */
    public function __construct(
        private readonly Transport $inner,
        private readonly array $recipients,
    ) {
        if ($recipients === []) {
            throw new \InvalidArgumentException('A redirect needs at least one recipient.');
        }
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return $this->recipients;
    }

    public function supports(Message $message): bool
    {
        return $this->inner->supports($message);
    }

    public function send(Message $message): SentMessage
    {
        if (!$message instanceof Email) {
            return $this->inner->send($message);
        }

        $note = 'Redirected. To: ' . implode(', ', $message->to);
        if ($message->cc !== []) {
            $note .= '; Cc: ' . implode(', ', $message->cc);
        }
        if ($message->bcc !== []) {
            $note .= '; Bcc: ' . implode(', ', $message->bcc);
        }

        $html = $message->html === null ? null
            : '<p style="font:12px sans-serif;color:#666">' . htmlspecialchars($note, ENT_QUOTES) . '</p>' . $message->html;

        return $this->inner->send(new Email(
            $this->recipients,
            '[→ ' . implode(', ', $message->to) . '] ' . $message->subject,
            $note . "\n\n" . $message->text,
            $html,
            $message->from,
            $message->replyTo,
            [],
            [],
            $message->attachments,
        ));
    }
}
