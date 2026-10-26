<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\I18n\Translator;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;

/**
 * The invite and reset mails of Folio accounts: plain text, the words from Folio's lang files
 * (folio.mail.invite.*, folio.mail.reset.*; a site's lang/{locale}/folio.php is laid over them)
 * in the recipient's interface language, else the site's default language. Queued with
 * Notifier::sendLater, so an answer takes as long whether or not a mail follows. The sender is
 * the mail channel's own (config/notify.php).
 */
final class StaffMailer
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly string $locale,
        private readonly string $site,
    ) {}

    /**
     * @param 'invite'|'reset' $mail
     * @param string|null $locale the recipient's interface language (null: the site default)
     */
    public function sendLater(string $mail, string $to, string $name, string $link, string $subject, ?string $locale = null): void
    {
        $locale ??= $this->locale;
        $vars = ['{name}' => self::oneLine($name), '{site}' => self::oneLine($this->site), '{link}' => $link];
        $this->notifier->sendLater(
            new Email(
                to: $to,
                subject: strtr($this->translator->get('folio.mail.' . $mail . '.subject', [], $locale), $vars),
                text: strtr($this->translator->get('folio.mail.' . $mail . '.body', [], $locale), $vars),
            ),
            static function () use ($mail, $subject): void {
                error_log('folio: ' . $mail . ' mail failed for account ' . $subject); // no address, no exception text
            },
        );
    }

    /** Control characters (CR/LF included) collapse to one space: a value must not break a header line. */
    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $value));
    }
}
