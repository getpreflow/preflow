<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\SentMessage;
use Preflow\Notify\TransportException;

/**
 * Composes a mail from its content (plain text part plus the HTML part from the layout) and sends
 * it through the Notifier. The subject is made one line. Unless given: from is config/notify.php
 * mail.from, else the email channel's own; the template is mail.layout, else the layout's own;
 * the accent is mail.accent (also for an accent that is not a hex colour).
 */
final class Mailer
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly MailLayout $layout,
        private readonly MailConfig $config,
    ) {}

    public function compose(MailContent $content, string $to, ?string $from = null, ?string $template = null, ?string $accent = null): Email
    {
        return new Email(
            to: $to,
            subject: MailText::oneLine($content->subject),
            text: $content->text,
            html: $this->layout->html($content, $this->accent($accent), $template ?? $this->config->layout),
            from: $from ?? $this->config->from,
        );
    }

    /** The given accent when it is a hex colour, else mail.accent. */
    private function accent(?string $accent): string
    {
        return $accent !== null && preg_match(MailConfig::ACCENT, $accent) === 1 ? $accent : $this->config->accent;
    }

    /** At once, so the caller knows whether it went out. @throws TransportException */
    public function send(MailContent $content, string $to, ?string $from = null, ?string $template = null, ?string $accent = null): SentMessage
    {
        return $this->notifier->send($this->compose($content, $to, $from, $template, $accent));
    }

    /**
     * Queued: goes out after the response (Notifier::sendLater()); a failure reaches $onFailure, else the log.
     *
     * @param ?\Closure(\Throwable): void $onFailure
     */
    public function sendLater(MailContent $content, string $to, ?\Closure $onFailure = null, ?string $from = null, ?string $template = null, ?string $accent = null): void
    {
        $this->notifier->sendLater($this->compose($content, $to, $from, $template, $accent), $onFailure);
    }
}
