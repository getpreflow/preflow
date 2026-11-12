<?php

declare(strict_types=1);

namespace Preflow\Rbac\Staff;

use Preflow\Data\DynamicRecord;
use Preflow\I18n\Translator;
use Preflow\Notify\Mail\MailAction;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\Mailer;
use Preflow\Notify\Mail\MailText;

/**
 * The invite and reset mails of staff accounts: the words from rbac's lang files (rbac-mail.invite.*,
 * rbac-mail.reset.*; a site's lang/{locale}/rbac-mail.php is laid over them) in the account's
 * language (StaffLocales), else the site's default language. Composed by notify's Mailer (a plain
 * text part and an HTML part with the link as the button, rbac-mail.*.action) and queued with
 * sendLater, so an answer takes as long whether or not a mail follows. Sender and look are the
 * mail settings' (config/notify.php). {site} is the site's name (notify's MailText::siteName());
 * without one the mail uses the texts *.subject_without_site and *.body_without_site, which name no site.
 */
final class StaffMail
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Translator $translator,
        private readonly string $locale,
        private readonly ?string $site,
        private readonly ?StaffLocales $locales = null,
    ) {}

    /**
     * Queues the mail to the account's address; a failure is logged (with the account, no address).
     *
     * @param 'invite'|'reset' $mail
     */
    public function sendLater(string $mail, DynamicRecord $staff, string $link): void
    {
        $to = (string) $staff->get('email');
        $name = trim((string) $staff->get('name'));
        $this->sendTo($mail, $to, $name !== '' ? $name : $to, $link, $staff->getType()->key . ':' . $staff->getId(), $this->locales?->locale($staff));
    }

    /**
     * Queues the mail to $to, for any account with a password link (Folio's forgot-password page
     * sends its reset mail through here when rbac is installed); a failure is logged with $account.
     *
     * @param 'invite'|'reset' $mail
     * @param string $account names the account in the log line (never the address)
     * @param string|null $locale the recipient's language (null: the site's default)
     */
    public function sendTo(string $mail, string $to, string $name, string $link, string $account, ?string $locale = null): void
    {
        $locale ??= $this->locale;
        $vars = ['{name}' => MailText::oneLine($name), '{site}' => MailText::oneLine($this->site ?? ''), '{link}' => $link];
        $suffix = $this->site === null ? '_without_site' : '';
        $this->mailer->sendLater(
            new MailContent(
                subject: strtr($this->text($mail, 'subject' . $suffix, $locale), $vars),
                text: strtr($this->text($mail, 'body' . $suffix, $locale), $vars),
                lang: $locale,
                action: new MailAction($this->text($mail, 'action', $locale), $link),
                links: [$link],
            ),
            $to,
            static function () use ($mail, $account): void {
                error_log('rbac: ' . $mail . ' mail failed for account ' . $account); // no address, no exception text
            },
        );
    }

    private function text(string $mail, string $part, string $locale): string
    {
        return $this->translator->get('rbac-mail.' . $mail . '.' . $part, [], $locale);
    }
}
