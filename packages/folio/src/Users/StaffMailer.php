<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\I18n\Translator;
use Preflow\Notify\Mail\MailAction;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\Mailer;
use Preflow\Notify\Mail\MailText;

/**
 * The invite and reset mails of Folio accounts: the words from Folio's lang files
 * (folio.mail.invite.*, folio.mail.reset.*; a site's lang/{locale}/folio.php is laid over them)
 * in the recipient's interface language, else the site's default language. Composed by notify's
 * Mailer (a plain text part and an HTML part with the link as the button, folio.mail.*.action)
 * and queued with sendLater, so an answer takes as long whether or not a mail follows. Sender and
 * look are the mail settings' (config/notify.php). {site} is the site's name (notify's MailText::siteName());
 * without one the mail uses the texts folio.mail.*.subject_without_site and body_without_site, which name no site.
 * Where preflow/rbac is installed, its StaffMail sends them instead (rbac-mail.*, the same words): staff
 * mails have one source, whichever page asks for one.
 */
final class StaffMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Translator $translator,
        private readonly string $locale,
        private readonly ?string $site,
        private readonly ?\Preflow\Rbac\Staff\StaffMail $rbac = null,
    ) {}

    /** @deprecated since 0.69, removed in 0.70: use notify's MailText::siteName(). */
    public static function siteName(mixed $appName, mixed $appUrl): ?string
    {
        return MailText::siteName($appName, $appUrl);
    }

    /**
     * @param 'invite'|'reset' $mail
     * @param string|null $locale the recipient's interface language (null: the site default)
     */
    public function sendLater(string $mail, string $to, string $name, string $link, string $subject, ?string $locale = null): void
    {
        if ($this->rbac !== null) {
            $this->rbac->sendTo($mail, $to, $name, $link, $subject, $locale);

            return;
        }
        $locale ??= $this->locale;
        $vars = ['{name}' => MailText::oneLine($name), '{site}' => MailText::oneLine($this->site ?? ''), '{link}' => $link];
        $suffix = $this->site === null ? '_without_site' : '';
        $this->mailer->sendLater(
            new MailContent(
                subject: strtr($this->translator->get('folio.mail.' . $mail . '.subject' . $suffix, [], $locale), $vars),
                text: strtr($this->translator->get('folio.mail.' . $mail . '.body' . $suffix, [], $locale), $vars),
                lang: $locale,
                action: new MailAction($this->translator->get('folio.mail.' . $mail . '.action', [], $locale), $link),
                links: [$link],
            ),
            $to,
            static function () use ($mail, $subject): void {
                error_log('folio: ' . $mail . ' mail failed for account ' . $subject); // no address, no exception text
            },
        );
    }
}
