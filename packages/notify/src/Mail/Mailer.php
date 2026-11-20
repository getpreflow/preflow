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
 *
 * The Notifier is a Notifier, or a Closure that returns the one to use: called at each send, so a
 * Notifier bound after the Mailer was built (a provider's, a test's) is the one the mail goes to
 * and the one whose flushDeferred() sends what sendLater() queued. Core passes a Closure that
 * reads the container.
 *
 * The HTML part is rendered inside the ComposeScope (since 0.81.0) for the content's language
 * (when it has none, the scope's: the current render's locale with core, else 'en'); the layout
 * gets the content with that language:
 * notify's own DirectCompose renders where the Mailer is called; core's renders in the
 * recipient's context, so the layout's texts and locale are the reader's, not the sender's.
 * Whether send() delivers is the Notifier's to decide (a preview or an audit sends nothing).
 */
final class Mailer
{
    /**
     * @param Notifier|\Closure(): Notifier $notifier
     * @param ComposeScope $scope since 0.81.0: where the HTML part is rendered (DirectCompose: here)
     */
    public function __construct(
        private readonly Notifier|\Closure $notifier,
        private readonly MailLayout $layout,
        private readonly MailConfig $config,
        private readonly ComposeScope $scope = new DirectCompose(),
    ) {}

    public function compose(MailContent $content, string $to, ?string $from = null, ?string $template = null, ?string $accent = null): Email
    {
        $accent = $this->accent($accent);
        $template ??= $this->config->layout;

        return new Email(
            to: $to,
            subject: MailText::oneLine($content->subject),
            text: $content->text,
            html: $this->scope->run($content->lang, fn (string $lang): string => $this->layout->html(
                $content->lang === $lang ? $content : new MailContent($content->subject, $content->text, $lang, $content->action, $content->vars, $content->links),
                $accent,
                $template,
            )),
            from: $from ?? $this->config->from,
        );
    }

    private function notifier(): Notifier
    {
        return $this->notifier instanceof Notifier ? $this->notifier : ($this->notifier)();
    }

    /** The given accent when it is a hex colour, else mail.accent. */
    private function accent(?string $accent): string
    {
        return $accent !== null && preg_match(MailConfig::ACCENT, $accent) === 1 ? $accent : $this->config->accent;
    }

    /**
     * At once, so the caller knows whether it went out (where the Notifier's queue does not
     * deliver, a preview or an audit, it is recorded instead and answers as if sent).
     *
     * @throws TransportException
     */
    public function send(MailContent $content, string $to, ?string $from = null, ?string $template = null, ?string $accent = null): SentMessage
    {
        return $this->notifier()->send($this->compose($content, $to, $from, $template, $accent));
    }

    /**
     * Queued: goes out after the response (Notifier::sendLater()); a failure reaches $onFailure, else the log.
     *
     * @param ?\Closure(\Throwable): void $onFailure
     */
    public function sendLater(MailContent $content, string $to, ?\Closure $onFailure = null, ?string $from = null, ?string $template = null, ?string $accent = null): void
    {
        $this->notifier()->sendLater($this->compose($content, $to, $from, $template, $accent), $onFailure);
    }
}
